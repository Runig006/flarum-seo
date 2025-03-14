<?php
namespace V17Development\FlarumSeo\Listeners;

// FlarumSEO classes
use V17Development\FlarumSeo\Managers\Discussion;
use V17Development\FlarumSeo\Managers\Page;
use V17Development\FlarumSeo\Managers\Profile;
use V17Development\FlarumSeo\Managers\QADiscussion;
use V17Development\FlarumSeo\Managers\Tag;
use V17Development\FlarumSeo\Extend;

// Flarum classes
use Flarum\Http\UrlGenerator;
use Flarum\Frontend\Document;
use Flarum\Settings\SettingsRepositoryInterface;

// Laravel classes
use Psr\Http\Message\ServerRequestInterface;

/**
 * Class PageListener
 * @package V17Development\FlarumSeo\Listeners
 */
class PageListener
{
    // Config
    protected $applicationUrl;

    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    protected $enabled_extensions;

    // Document
    protected $flarumDocument;

    private $requestType = null;

    private $canonicalUrl = null;

    // Schema.org LD JSON
    protected $schemaArray = [
        '@context' => 'http://schema.org',
        '@type' => 'WebPage'
    ];

    protected $schemaBreadcrumb = [];

    // Meta data with property tags
    protected $metaProperty;

    protected $discussionType = 1; // Special Google results as default, check check readme for different results

    /**
     * PageListener constructor.
     *
     * @param SettingsRepositoryInterface $settings
     * @param Profile $profileManager
     * @param Tag $tagManager
     * @param Discussion $discussionManager
     */
    public function __construct(
        SettingsRepositoryInterface $settings,
        UrlGenerator $url
    ) {
        // Get Flarum settings
        $this->settings = $settings;

        // Set forum base URL
        $this->applicationUrl = $url->to('forum')->base();

        // List enabled extensions
        $this->enabled_extensions = json_decode($this->settings->get("extensions_enabled"), true);

        // Fancy SEO question-answer?
        $this->discussionType = $this->settings->get("seo_post_crawler") === '1' ? 2 : 1;

        // When Flarum likes is disabled, then automatically index as default discussion page
        if(!$this->extensionEnabled("flarum-likes"))
        {
            $this->discussionType = 1;
        }

        // Initialize SEO extender
        Extend::init($this);

        // Settings debug settings: var_dump($this->settings->all());exit;
    }

    /**
     * Get current Flarum document and current Server Request
     *
     * @param Document $flarumDocument
     * @param ServerRequestInterface $serverRequestInterface
     */
    public function __invoke(Document $flarumDocument, ServerRequestInterface $serverRequestInterface)
    {
        // Flarum document
        $this->flarumDocument = $flarumDocument;

        // Default site tags
        $this->setSiteTags();

        // Check out type of page
        $this->determine($serverRequestInterface);

        // TODO: Move finish function back to BeforePageRenders
        // After PR and release of BETA 14
        $this->finish($serverRequestInterface);
    }

    /**
     * Determine the current page type
     */
    private function determine($serverRequest)
    {
        // Request type
        $routeName = $serverRequest->getAttribute('routeName');

        // Query params
        $queryParams = $serverRequest->getQueryParams();

        // User profile page
        if($routeName === 'user') {
            resolve(Profile::class)->handle($this, isset($queryParams['username']) ? $queryParams['username'] : false);
        }

        // Tag page
        else if($routeName === 'tag') {
            resolve(Tag::class)->handle($this, isset($queryParams['slug']) ? $queryParams['slug'] : false);
        }

        // Friends Of Flarum pages
        else if($routeName === 'pages.home') {
            resolve(Page::class)->handle($this, isset($queryParams['id']) ? $queryParams['id'] : false);
        }

        // Default SEO (no fancy QA layout)
        else if($routeName === 'discussion' && $this->discussionType === 1) {
            resolve(Discussion::class)->handle($this, isset($queryParams['id']) ? $queryParams['id'] : false);
        }

        // QuestionAnswer page
        else if($routeName === 'discussion' && $this->discussionType === 2) {
            resolve(QADiscussion::class)->handle($this, isset($queryParams['id']) ? $queryParams['id'] : false);
        }

        // Home page/discussion overview page
        else if($routeName === "default" || $routeName === "index") {
            $this->setDescription($this->settings->get('forum_description'));
            $this->setKeywords($this->settings->get('forum_keywords'));
            $this->setTitle($this->settings->get('forum_title'));
            $this->setUrl();
            $this->setCanonicalUrl('');

            // Update meta tag URL when it's the discussion overview page
            if($routeName === "default" && $this->settings->get('default_route') !== '/all') {
                $this->setUrl('/all');

                $this->setCanonicalUrl('/all');
            }
        }
    }

    /**
     * Default site meta tags
     * Available for all webpages
     */
    private function setSiteTags()
    {
        $applicationName = $this->settings->get('forum_title');
        $applicationDescription = $this->settings->get('forum_description');
        $applicationFavicon = $this->settings->get('favicon_path');
        $applicationLogo = $this->settings->get('logo_path');
        $applicationSeoSocialMediaImage = $this->settings->get('seo_social_media_image_path');
        $twitterCardLargeSize = $this->settings->get('seo_twitter_card_size', 'large') === 'large';

        $this
            // Add application name
            ->setMetaTag('application-name', $applicationName)
            ->setMetaPropertyTag('og:site_name', $applicationName)
            ->setMetaPropertyTag('og:type', 'website')

            // Robots, follow please! :)
            ->setMetaTag('robots', 'index, follow')

            // Twitter card
            ->setMetaTag('twitter:card', $twitterCardLargeSize ? 'summary_large_image' : 'summary');

        // Add application information
        $this->setSchemaJson('publisher', [
            "@type" => "Organization",
            "name" => $applicationName,
            "url" => $this->applicationUrl,
            "description" => $applicationDescription,
            "logo" => $applicationLogo ? $this->applicationUrl . '/assets/' . $applicationLogo : null
        ]);

        // Set image
        if($applicationSeoSocialMediaImage !== null)
        {
            $this->setImage($this->applicationUrl . '/assets/' . $applicationSeoSocialMediaImage);
        }
        // Fallback to the logo
        else if($applicationLogo !== null)
        {
            $this->setImage($this->applicationUrl . '/assets/' . $applicationLogo);
        }
        // Fallback to the favicon
        else if($applicationFavicon !== null)
        {
            $this->setImage($this->applicationUrl . '/assets/' . $applicationFavicon);
        }
    }

    /**
     * Finish process and output language, meta property tags, canonical urls & Schema.org json
     */
    public function finish($serverRequest)
    {
        // Add language attribute to html tag
        $this->flarumDocument->language = $serverRequest->getAttribute('locale');

        // Write meta property tags
        foreach ($this->metaProperty as $name => $content) {
            $this->flarumDocument->head[] = '<meta property="'.e($name).'" content="'.e($content).'">';
        }

        // Override Flarum default canonical url
        if($this->canonicalUrl !== null)
        {
            $this->flarumDocument->canonicalUrl = $this->canonicalUrl;
        }

        // Add schema.org json
        $this->flarumDocument->head[] = $this->writeSchemesOrgJson();
    }

    /**
     * Schema.org json
     */
    private function writeSchemesOrgJson()
    {
        $show = [];
        $show[] = $this->schemaArray;

        if(count($this->schemaBreadcrumb) > 0) {
            $show[] = $this->schemaBreadcrumb;
        }

        $show[] = $this->addSearchBar();

        return '<script type="application/ld+json">' . json_encode($show, true) . '</script>';
    }

    /**
     * Add the potential search bar
     *
     * @return array
     */
    private function addSearchBar()
    {
        return [
            '@context' => 'http://schema.org',
            '@type' => 'WebSite',
            'url' => $this->applicationUrl . '/',
            'potentialAction' => [
                "@type" => "SearchAction",
                "target" => $this->applicationUrl . '/?q={search_term_string}',
                "query-input" => 'required name=search_term_string'
            ]
        ];
    }

    /**
     * @param $key
     * @param $value
     * @return PageListener
     */
    public function setMetaPropertyTag($key, $value)
    {
        $this->metaProperty[$key] = $value;

        return $this;
    }

    /**
     * @param $key
     * @param $value
     * @return PageListener
     */
    public function setMetaTag($key, $value)
    {
        $this->flarumDocument->meta[$key] = $value;

        return $this;
    }

    /**
     * @param $key
     * @param $value
     * @return PageListener
     */
    public function setSchemaJson($key, $value)
    {
        $this->schemaArray[$key] = $value;

        return $this;
    }

    /**
     * @param $discussion
     */
    public function setSchemaBreadcrumb($discussion)
    {
        $tags = $discussion->getAttribute("tags");
        $list = [];

        // Don't add the list, there were no tags
        if(count($tags) === 0) return;

        // Foreach tags
        $number = 0;
        foreach ($tags as $tag)
        {
            $number++;
            $list = [
                '@type' => 'ListItem',
                'name' => $tag->getAttribute('name'),
                'item' => $this->applicationUrl . '/t/' . $tag->getAttribute('slug'),
                'position' => $number
            ];
        }

        $this->schemaBreadcrumb = [
            "@context" => "http://schema.org",
            "@type" => "BreadcrumbList",
            "itemListElement" => $list
        ];
    }

    /**
     * @param $name
     * @return bool
     */
    public function extensionEnabled($name)
    {
        return in_array($name, $this->enabled_extensions);
    }

    /**
     * Current page URL
     *
     * @param $path
     * @return PageListener
     */
    public function setUrl($path = '', $addApplicationUrl = true)
    {
        if($addApplicationUrl) {
            $path = $this->applicationUrl . $path;
        }

        $this->setMetaTag('twitter:url', $path);
        $this->setMetaPropertyTag('og:url', $path);
        $this->setSchemaJson("url", $path);

        return $this;
    }

    /**
     * Set canonical url
     *
     * @param $path
     * @return PageListener
     */
    public function setCanonicalUrl($path)
    {
        $this->canonicalUrl = $this->applicationUrl . $path;

        return $this;
    }

    /**
     * @param $path
     * @return string
     */
    public function getApplicationPath($path)
    {
        return $this->applicationUrl . $path;
    }

    /**
     * Set title
     *
     * @param $title
     * @param $headline
     * @return PageListener
     */
    public function setTitle($title, $headline = false)
    {
        $this
            ->setMetaPropertyTag('og:title', $title)
            ->setMetaTag('twitter:title', $title);

        // Set headline
        if($headline === true)
        {
            $this->setSchemaJson("headline", $title);
        }

        return $this;
    }

    /**
     * Set description
     *
     * @param $content
     * @return PageListener
     */
    public function setDescription($content)
    {
        $description = strip_tags($content);
        $description = trim(preg_replace('/\s+/', ' ', mb_substr($description, 0, 157))) . (mb_strlen($description) > 157 ? '...' : '');

        $this
            ->setMetaPropertyTag('og:description', $description)
            ->setMetaTag('description', $description)
            ->setMetaTag('twitter:description', $description)
            ->setSchemaJson("description", $description);

        return $this;
    }

    /**
     * Set page keywords
     * 
     * @param $keywords
     */
    public function setKeywords($keywords)
    {
        if(!$keywords || $keywords === "") return;

        // Possible array of keywords
        if(is_array($keywords)) {
            $keywords = implode(", ", $keywords);
        }

        // Set keywords meta tag
        $this->setMetaTag('keywords', $keywords);
    }

    /**
     * Set setImageFromContent
     *
     * @param $content
     * @return PageListener
     */
    public function setImageFromContent($content = null)
    {
        // Check post content is not empty
        if($content !== null) {
            // Read Post content and filter image url
            $pattern = '/(?<=src=")((http.*?\.)(jpe?g|png|[tg]iff?|svg))(?=")/';

            // Use image from post for social media og:image
            if (preg_match_all($pattern, $content, $matches) && count($matches) > 0) {
                $contentImage = $matches[0][0];

                if ($contentImage !== null) {
                    $this->setImage($contentImage);
                    return $this;
                }
            }
        }

        return $this;
    }


    /**
     * Set published on
     *
     * @param $published
     * @return PageListener
     */
    public function setPublishedOn($published)
    {
        $date = (new \DateTime($published))->format("c");

        $this
            ->setMetaTag('article:published_time', $date)
            ->setSchemaJson('datePublished', $date);

        return $this;
    }

    /**
     * Set updated time
     * Only used when a discussion has newer posts
     *
     * @param $updated
     * @return PageListener
     */
    public function setUpdatedOn($updated)
    {
        $date = (new \DateTime($updated))->format("c");

        $this
            ->setMetaTag('article:updated_time', $date)
            ->setSchemaJson('dateModified', $date);

        return $this;
    }

    /**
     * Set page image
     *
     * @param $imagePath
     * @return PageListener
     */
    public function setImage($imagePath)
    {
        return $this
            ->setMetaPropertyTag('og:image', $imagePath)
            ->setMetaTag('og:image', $imagePath)
            ->setMetaTag('twitter:image', $imagePath)
            ->setSchemaJson('image', $imagePath);
    }

    /**
     * Set page title
     *
     * @param $title
     */
    public function setPageTitle($title)
    {
        $this->flarumDocument->title = $title;
        
        return $this;
    }
}
