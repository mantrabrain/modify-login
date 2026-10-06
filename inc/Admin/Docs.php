<?php
/**
 * In-plugin documentation.
 *
 * @package Authlify
 */

namespace Authlify\Admin;

use Authlify\Plugin;

defined('ABSPATH') || exit;

/**
 * The Docs screen: a searchable documentation centre inside wp-admin.
 *
 * Articles are plain arrays (id, category, title, summary, body HTML, pro,
 * keywords). Authlify Pro replaces the Pro overview articles with its own
 * full articles through the `authlify_docs_articles` filter.
 *
 * Every article describes behaviour that exists in the code; the sources each
 * one was checked against are listed in the development notes, not here.
 *
 * @since 3.0.0
 */
final class Docs
{
    const SLUG = 'authlify-docs';

    /**
     * Built articles (cache).
     *
     * @var array|null
     */
    private static $articles = null;

    /**
     * Wire up (called from Menu::init(), admin only).
     *
     * @since 3.0.0
     */
    public static function init()
    {
        add_filter('authlify_admin_pages', array(__CLASS__, 'page'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'), 20);

        Upsell::init();
    }

    /**
     * Register the Docs page as the last menu item.
     *
     * @param array $pages Pages.
     * @return array
     * @since 3.0.0
     */
    public static function page($pages)
    {
        $pages['docs'] = array(self::SLUG, __('Docs', 'modify-login'), array(__CLASS__, 'render'), 95);

        return $pages;
    }

    /**
     * URL of the Docs page, optionally of one article.
     *
     * @param string $article_id Article ID.
     * @return string
     * @since 3.0.0
     */
    public static function url($article_id = '')
    {
        $url = Menu::slug_url(self::SLUG);

        return '' !== $article_id ? $url . '#' . sanitize_key($article_id) : $url;
    }

    /**
     * Docs stylesheet and script, on the Docs screen only.
     *
     * @since 3.0.0
     */
    public static function assets()
    {
        if (self::SLUG !== Menu::current_slug()) {
            return;
        }

        wp_enqueue_style('authlify-docs', AUTHLIFY_URL . 'assets/admin/docs.css', array('authlify-admin'), AUTHLIFY_VERSION);
        wp_enqueue_script('authlify-docs', AUTHLIFY_URL . 'assets/admin/docs.js', array(), AUTHLIFY_VERSION, true);
        wp_localize_script('authlify-docs', 'authlifyDocs', array(
            /* translators: %d: number of articles */
            'countOne' => __('%d article matches your search.', 'modify-login'),
            /* translators: %d: number of articles */
            'countMany' => __('%d articles match your search.', 'modify-login'),
            'countNone' => __('No articles match your search. Try a shorter or different word.', 'modify-login'),
            /* translators: %s: search words */
            'resultsFor' => __('Results for “%s”', 'modify-login'),
            'copy' => __('Copy', 'modify-login'),
            'copyCode' => __('Copy code to clipboard', 'modify-login'),
            'copied' => __('Copied', 'modify-login'),
        ));
    }

    /**
     * Categories: key => array( label, description ).
     *
     * @return array
     * @since 3.0.0
     */
    public static function categories()
    {
        return array(
            'start' => array(__('Getting started', 'modify-login'), __('Install, first-run setup and a safe first week.', 'modify-login')),
            'features' => array(__('Free features', 'modify-login'), __('What each feature does, where it lives and how it behaves.', 'modify-login')),
            'pro' => array(__('Pro features', 'modify-login'), __('What Authlify Pro adds for teams, stores and agencies.', 'modify-login')),
            'config' => array(__('Configuration', 'modify-login'), __('Every setting with its default and a recommended value.', 'modify-login')),
            'howto' => array(__('How-to guides', 'modify-login'), __('Step-by-step answers to common tasks.', 'modify-login')),
            'trouble' => array(__('Troubleshooting', 'modify-login'), __('Locked out, lost URL, cache and proxy problems.', 'modify-login')),
            'faq' => array(__('FAQ', 'modify-login'), __('Free and Pro, compatibility, data and updates.', 'modify-login')),
            'privacy' => array(__('Security and privacy', 'modify-login'), __('What is stored, for how long, and what is ever sent elsewhere.', 'modify-login')),
            'dev' => array(__('Developers', 'modify-login'), __('Hooks, constants, WP-CLI and REST routes.', 'modify-login')),
        );
    }

    /**
     * Icon of a category (UI::icon() name).
     *
     * @param string $category Category key.
     * @return string
     * @since 3.0.0
     */
    public static function category_icon($category)
    {
        $icons = array(
            'start' => 'spark',
            'features' => 'shield-check',
            'pro' => 'badge',
            'config' => 'settings',
            'howto' => 'list',
            'trouble' => 'wrench',
            'faq' => 'help',
            'privacy' => 'lock',
            'dev' => 'code',
        );

        return isset($icons[$category]) ? $icons[$category] : 'book';
    }

    /**
     * All articles, keyed by ID, in display order.
     *
     * @return array id => array( id, category, title, summary, body, pro, keywords ).
     * @since 3.0.0
     */
    public static function articles()
    {
        if (null !== self::$articles) {
            return self::$articles;
        }

        $list = array_merge(
            self::start_articles(),
            self::feature_articles(),
            self::pro_articles(),
            self::config_articles(),
            self::howto_articles(),
            self::trouble_articles(),
            self::faq_articles(),
            self::privacy_articles(),
            self::dev_articles()
        );

        $articles = array();
        foreach ($list as $article) {
            $articles[$article['id']] = $article;
        }

        /**
         * Filters the documentation articles. Authlify Pro replaces the Pro
         * overview articles (same IDs) with full articles and adds its own.
         *
         * @param array $articles id => array(
         *     @type string   $id       Article ID (used in the #anchor).
         *     @type string   $category One of Docs::categories() keys.
         *     @type string   $title    Title.
         *     @type string   $summary  One-sentence summary.
         *     @type string   $body     Body HTML (h3, h4, p, lists, code, pre, tables, links).
         *     @type bool     $pro      Whether the article describes an Authlify Pro feature.
         *     @type string[] $keywords Extra search words.
         * ).
         * @since 3.0.0
         */
        $articles = (array) apply_filters('authlify_docs_articles', $articles);

        $categories = self::categories();
        $clean = array();
        foreach ($articles as $key => $article) {
            if (!is_array($article) || empty($article['title'])) {
                continue;
            }
            $id = sanitize_key(!empty($article['id']) ? $article['id'] : $key);
            $article = wp_parse_args($article, array('category' => 'features', 'summary' => '', 'body' => '', 'pro' => false, 'keywords' => array()));
            $article['id'] = $id;
            $article['category'] = isset($categories[$article['category']]) ? $article['category'] : 'features';
            $article['keywords'] = array_map('strval', (array) $article['keywords']);
            $article['pro'] = (bool) $article['pro'];
            $clean[$id] = $article;
        }

        // Group by category, keeping each category's order.
        $ordered = array();
        foreach (array_keys($categories) as $category) {
            foreach ($clean as $id => $article) {
                if ($article['category'] === $category) {
                    $ordered[$id] = $article;
                }
            }
        }

        self::$articles = $ordered;

        return self::$articles;
    }

    /**
     * One article's title (empty when it does not exist).
     *
     * @param string $article_id Article ID.
     * @return string
     * @since 3.0.0
     */
    public static function title($article_id)
    {
        $articles = self::articles();

        return isset($articles[$article_id]) ? self::brand_text($articles[$article_id]['title']) : '';
    }

    /**
     * The product name used in the docs (Authlify Pro white-label changes it).
     *
     * @return string
     * @since 3.0.0
     */
    public static function brand()
    {
        /**
         * Filters the product name used throughout the documentation.
         *
         * @param string $name Default "Authlify".
         * @since 3.0.0
         */
        $name = trim((string) apply_filters('authlify_docs_brand', 'Authlify'));

        return '' !== $name ? $name : 'Authlify';
    }

    /**
     * Replace the product name with the white-label name, leaving code
     * (namespaces such as \Authlify\Settings, AUTHLIFY_ constants, authlify_ hooks) intact.
     *
     * @param string $text Text or HTML.
     * @return string
     */
    private static function brand_text($text)
    {
        $brand = self::brand();
        if ('Authlify' === $brand) {
            return $text;
        }

        return (string) preg_replace('/(?<![\\\\\w\/-])Authlify(?![\\\\\w\/-])/', $brand, $text);
    }

    /**
     * Authlify Pro on the product page, with campaign parameters.
     *
     * @param string $content What the link sits next to.
     * @param string $section Page section: pro, compare or pricing (default by $content).
     * @return string
     * @since 3.0.0
     */
    public static function upgrade_url($content = 'docs', $section = '')
    {
        if ('' === $section) {
            $section = in_array($content, array('plugins-screen', 'dashboard'), true) ? 'pricing' : 'pro';
        }

        return add_query_arg(array(
            'utm_source' => 'authlify-free',
            'utm_medium' => 'plugin',
            'utm_campaign' => 'upgrade',
            'utm_content' => sanitize_key($content),
        ), ProPage::URL) . '#' . sanitize_key($section);
    }

    /**
     * Tags allowed in article bodies.
     *
     * @return array
     */
    private static function body_html()
    {
        $plain = array();
        $classed = array('class' => true);

        return array(
            'h3' => array('id' => true),
            'h4' => $plain,
            'p' => $classed,
            'ul' => $classed,
            'ol' => $classed,
            'li' => $plain,
            'a' => array('href' => true, 'target' => true, 'rel' => true, 'class' => true),
            'code' => $plain,
            'pre' => $plain,
            'kbd' => $plain,
            'strong' => $plain,
            'em' => $plain,
            'br' => $plain,
            'div' => $classed,
            'span' => $classed,
            'table' => $classed,
            'thead' => $plain,
            'tbody' => $plain,
            'tr' => $plain,
            'th' => array('scope' => true),
            'td' => $plain,
            'dl' => $plain,
            'dt' => $plain,
            'dd' => $plain,
        );
    }

    /**
     * Render the Docs screen.
     *
     * @since 3.0.0
     */
    public static function render()
    {
        $articles = self::articles();
        $categories = self::categories();
        $grouped = array();
        foreach ($articles as $id => $article) {
            $grouped[$article['category']][$id] = $article;
        }
        $ids = array_keys($articles);
        $has_pro = Plugin::has_pro();
        $brand = self::brand();
        ?>
        <div class="wrap authlify-page authlify-docs" data-authlify-docs>
            <?php
            UI::header(
                __('Docs', 'modify-login'),
                /* translators: %s: product name */
                sprintf(__('How %s works, how to set it up, and how to get back in if something goes wrong.', 'modify-login'), $brand),
                // A white-labelled site keeps the online Authlify docs out of view.
                'Authlify' === $brand ? array(array('label' => __('Full documentation online', 'modify-login'), 'url' => 'https://matrixaddons.com/plugins/authlify/docs/', 'target' => true)) : array()
            );
            ?>

            <div class="authlify-docs__layout">
                <aside class="authlify-docs__side">
                    <div class="authlify-docs__search" role="search">
                        <label for="authlify-docs-q" class="screen-reader-text"><?php esc_html_e('Search the documentation', 'modify-login'); ?></label>
                        <span class="authlify-docs__search-icon" aria-hidden="true"><?php echo UI::icon('search', 16); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
                        <input type="search" id="authlify-docs-q" placeholder="<?php esc_attr_e('Search the docs', 'modify-login'); ?>" autocomplete="off" spellcheck="false" aria-describedby="authlify-docs-count" aria-controls="authlify-docs-results">
                        <kbd class="authlify-docs__key" aria-hidden="true">/</kbd>
                    </div>
                    <p id="authlify-docs-count" class="authlify-docs__count" aria-live="polite" aria-atomic="true"></p>

                    <div class="authlify-docs__jump">
                        <label for="authlify-docs-jump"><?php esc_html_e('Article', 'modify-login'); ?></label>
                        <select id="authlify-docs-jump">
                            <option value=""><?php esc_html_e('Overview', 'modify-login'); ?></option>
                            <?php foreach ($categories as $cat => $meta) : ?>
                                <?php if (empty($grouped[$cat])) { continue; } ?>
                                <optgroup label="<?php echo esc_attr($meta[0]); ?>">
                                    <?php foreach ($grouped[$cat] as $id => $article) : ?>
                                        <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html(self::brand_text($article['title']) . ($article['pro'] ? ' (Pro)' : '')); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <nav class="authlify-docs__nav" aria-label="<?php esc_attr_e('Documentation', 'modify-login'); ?>">
                        <a class="authlify-docs__home" href="#" data-doc-link=""><?php echo UI::icon('book', 18); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e('Overview', 'modify-login'); ?></span></a>
                        <?php foreach ($categories as $cat => $meta) : ?>
                            <?php if (empty($grouped[$cat])) { continue; } ?>
                            <div class="authlify-docs__group" data-doc-group="<?php echo esc_attr($cat); ?>">
                                <p class="authlify-docs__group-title" id="authlify-docs-cat-<?php echo esc_attr($cat); ?>"><?php echo UI::icon(self::category_icon($cat), 14); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php echo esc_html($meta[0]); ?></span></p>
                                <ul aria-labelledby="authlify-docs-cat-<?php echo esc_attr($cat); ?>">
                                    <?php foreach ($grouped[$cat] as $id => $article) : ?>
                                        <li data-doc-item="<?php echo esc_attr($id); ?>">
                                            <a href="#<?php echo esc_attr($id); ?>" data-doc-link="<?php echo esc_attr($id); ?>"><?php echo esc_html(self::brand_text($article['title'])); ?><?php echo $article['pro'] ? ' <span class="authlify-pro-tag">Pro</span>' : ''; ?></a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </nav>
                </aside>

                <div class="authlify-docs__main">
                    <section class="authlify-docs__results" id="authlify-docs-results" hidden aria-labelledby="authlify-docs-results-title">
                        <h2 id="authlify-docs-results-title" class="authlify-docs__results-title"><?php esc_html_e('Search results', 'modify-login'); ?></h2>
                        <ol class="authlify-docs__results-list"></ol>
                    </section>

                    <section class="authlify-docs__overview" data-doc-overview aria-labelledby="authlify-docs-overview-title">
                        <h2 id="authlify-docs-overview-title" class="screen-reader-text"><?php esc_html_e('Overview', 'modify-login'); ?></h2>
                        <div class="authlify-docs__start">
                            <div>
                                <h3><?php esc_html_e('New here?', 'modify-login'); ?></h3>
                                <p><?php esc_html_e('Start with the recommended setup. It takes about ten minutes and covers what matters most.', 'modify-login'); ?></p>
                            </div>
                            <a class="button button-primary" href="#recommended-setup"><?php esc_html_e('Recommended setup', 'modify-login'); ?></a>
                        </div>
                        <div class="authlify-docs__cards">
                            <?php foreach ($categories as $cat => $meta) : ?>
                                <?php if (empty($grouped[$cat])) { continue; } ?>
                                <div class="authlify-docs__card">
                                    <span class="authlify-docs__card-icon" aria-hidden="true"><?php echo UI::icon(self::category_icon($cat), 20); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span>
                                    <h3><?php echo esc_html($meta[0]); ?> <span class="authlify-docs__card-count"><?php echo esc_html(number_format_i18n(count($grouped[$cat]))); ?></span></h3>
                                    <p><?php echo esc_html($meta[1]); ?></p>
                                    <ul>
                                        <?php foreach (array_slice($grouped[$cat], 0, 4, true) as $id => $article) : ?>
                                            <li><a href="#<?php echo esc_attr($id); ?>"><?php echo esc_html(self::brand_text($article['title'])); ?></a></li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <?php if (count($grouped[$cat]) > 4) : ?>
                                        <a class="authlify-docs__card-more" href="#<?php echo esc_attr((string) key($grouped[$cat])); ?>">
                                            <?php
                                            /* translators: %d: number of articles */
                                            echo esc_html(sprintf(__('All %d articles', 'modify-login'), count($grouped[$cat])));
                                            ?>
                                            <span class="screen-reader-text"><?php echo esc_html(sprintf(/* translators: %s: category */ __('in %s', 'modify-login'), $meta[0])); ?></span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <?php foreach ($articles as $id => $article) : ?>
                        <?php
                        $index = array_search($id, $ids, true);
                        $prev = $index > 0 ? $ids[$index - 1] : '';
                        $next = $index < count($ids) - 1 ? $ids[$index + 1] : '';
                        $keywords = implode(' ', $article['keywords']);
                        ?>
                        <article class="authlify-docs__article" id="<?php echo esc_attr($id); ?>" data-doc="<?php echo esc_attr($id); ?>" data-category="<?php echo esc_attr($categories[$article['category']][0]); ?>" data-keywords="<?php echo esc_attr($keywords); ?>" aria-labelledby="<?php echo esc_attr($id); ?>-title" tabindex="-1">
                            <p class="authlify-docs__crumb"><?php echo esc_html($categories[$article['category']][0]); ?></p>
                            <h2 id="<?php echo esc_attr($id); ?>-title" class="authlify-docs__title"><?php echo esc_html(self::brand_text($article['title'])); ?><?php echo $article['pro'] ? ' <span class="authlify-pro-tag">Pro</span>' : ''; ?></h2>
                            <?php if ('' !== $article['summary']) : ?>
                                <p class="authlify-docs__lead"><?php echo esc_html(self::brand_text($article['summary'])); ?></p>
                            <?php endif; ?>

                            <?php if ($article['pro'] && !$has_pro) : ?>
                                <div class="authlify-docs__pro">
                                    <p><?php esc_html_e('This is part of Authlify Pro. Everything in the free plugin stays free.', 'modify-login'); ?></p>
                                    <div class="authlify-docs__pro-actions">
                                        <a class="button button-primary" href="<?php echo esc_url(self::upgrade_url($id)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Upgrade to Pro', 'modify-login'); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'modify-login'); ?></span></a>
                                        <a href="<?php echo esc_url(Menu::slug_url('authlify-pro')); ?>"><?php esc_html_e('Compare Free and Pro', 'modify-login'); ?></a>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="authlify-docs__body">
                                <?php echo wp_kses(self::brand_text($article['body']), self::body_html()); ?>
                            </div>

                            <nav class="authlify-docs__pager" aria-label="<?php esc_attr_e('More articles', 'modify-login'); ?>">
                                <?php if ('' !== $prev) : ?>
                                    <a class="authlify-docs__prev" href="#<?php echo esc_attr($prev); ?>"><span><?php esc_html_e('Previous', 'modify-login'); ?></span> <?php echo esc_html(self::brand_text($articles[$prev]['title'])); ?></a>
                                <?php endif; ?>
                                <?php if ('' !== $next) : ?>
                                    <a class="authlify-docs__next" href="#<?php echo esc_attr($next); ?>"><span><?php esc_html_e('Next', 'modify-login'); ?></span> <?php echo esc_html(self::brand_text($articles[$next]['title'])); ?></a>
                                <?php endif; ?>
                            </nav>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /* ----------------------------------------------------------------------
     * Authoring helpers
     * ------------------------------------------------------------------- */

    /**
     * Build an article.
     *
     * @param string $id       ID.
     * @param string $category Category key.
     * @param string $title    Title.
     * @param string $summary  Summary.
     * @param string $body     Body HTML.
     * @param array  $keywords Search words.
     * @param bool   $pro      Pro feature.
     * @return array
     */
    private static function a($id, $category, $title, $summary, $body, array $keywords = array(), $pro = false)
    {
        return array(
            'id' => $id,
            'category' => $category,
            'title' => $title,
            'summary' => $summary,
            'body' => $body,
            'pro' => $pro,
            'keywords' => $keywords,
        );
    }

    /**
     * Link to an admin page.
     *
     * @param string $slug  Page slug.
     * @param string $label Link text.
     * @param array  $args  Query args (e.g. tab).
     * @return string
     */
    private static function go($slug, $label, array $args = array())
    {
        return '<a href="' . esc_url(Menu::slug_url($slug, $args)) . '">' . esc_html($label) . '</a>';
    }

    /**
     * Link to another article.
     *
     * @param string $id    Article ID.
     * @param string $label Link text.
     * @return string
     */
    private static function doc($id, $label)
    {
        return '<a href="#' . esc_attr($id) . '">' . esc_html($label) . '</a>';
    }

    /**
     * A note or warning box. Starts with a bold word, so colour is not the only signal.
     *
     * @param string $html    Content HTML.
     * @param string $type    note or warning.
     * @return string
     */
    private static function note($html, $type = 'note')
    {
        $label = 'warning' === $type ? __('Warning:', 'modify-login') : __('Note:', 'modify-login');

        return '<div class="authlify-docs__note authlify-docs__note--' . esc_attr($type) . '"><p><strong>' . esc_html($label) . '</strong> ' . $html . '</p></div>';
    }

    /**
     * A code block.
     *
     * @param string $code Code (plain text).
     * @return string
     */
    private static function code($code)
    {
        return '<pre><code>' . esc_html($code) . '</code></pre>';
    }

    /**
     * A settings reference table.
     *
     * @param array $rows array( label, key, default, recommended, consequence ).
     * @return string
     */
    private static function table(array $rows)
    {
        $html = '<div class="authlify-docs__table"><table><thead><tr>'
            . '<th scope="col">' . esc_html__('Setting', 'modify-login') . '</th>'
            . '<th scope="col">' . esc_html__('Default', 'modify-login') . '</th>'
            . '<th scope="col">' . esc_html__('Recommended', 'modify-login') . '</th>'
            . '<th scope="col">' . esc_html__('What it changes', 'modify-login') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td><strong>' . esc_html($row[0]) . '</strong><br><code>' . esc_html($row[1]) . '</code></td><td>' . esc_html($row[2]) . '</td><td>' . esc_html($row[3]) . '</td><td>' . $row[4] . '</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /**
     * A schema default, formatted for the reference tables.
     *
     * @param string $key Settings key.
     * @return string
     */
    private static function def($key)
    {
        $schema = \Authlify\Settings::schema();
        if (!isset($schema[$key])) {
            return '';
        }
        $value = $schema[$key][1];
        if (is_bool($value)) {
            return $value ? __('On', 'modify-login') : __('Off', 'modify-login');
        }
        if (is_array($value)) {
            return $value ? implode(', ', $value) : __('(none)', 'modify-login');
        }

        return '' === (string) $value ? __('(empty)', 'modify-login') : (string) $value;
    }

    /**
     * Wrap paragraphs.
     *
     * @param string ...$paragraphs Paragraph HTML.
     * @return string
     */
    private static function p()
    {
        return '<p>' . implode('</p><p>', func_get_args()) . '</p>';
    }

    /**
     * An unordered list.
     *
     * @param array $items Item HTML.
     * @param bool  $ordered Ordered list.
     * @return string
     */
    private static function ul(array $items, $ordered = false)
    {
        $tag = $ordered ? 'ol' : 'ul';

        return '<' . $tag . '><li>' . implode('</li><li>', $items) . '</li></' . $tag . '>';
    }

    /**
     * Standard feature sections.
     *
     * @param array $sections heading => HTML. Headings: Purpose, Where to find it, …
     * @return string
     */
    private static function sections(array $sections)
    {
        $html = '';
        foreach ($sections as $heading => $content) {
            $html .= '<h3>' . esc_html($heading) . '</h3>' . $content;
        }

        return $html;
    }

    /* ----------------------------------------------------------------------
     * Getting started
     * ------------------------------------------------------------------- */

    /**
     * Getting started articles.
     *
     * @return array
     */
    private static function start_articles()
    {
        $c = 'start';

        return array(
            self::a('what-is-authlify', $c, __('What Authlify does', 'modify-login'), __('One plugin that protects the WordPress login, lets people prove who they are, and makes the login page look like your site.', 'modify-login'),
                self::p(
                    __('Authlify replaces several single-purpose login plugins. Everything below is in the free plugin, with no limits and no license.', 'modify-login')
                ) . self::ul(array(
                    __('<strong>A private login address.</strong> Move wp-login.php to an address only you know. Bots that request wp-login.php or /wp-admin/ get a normal “page not found”.', 'modify-login'),
                    __('<strong>Brute-force lockouts.</strong> An address that keeps guessing passwords has to wait, and the wait grows each time.', 'modify-login'),
                    __('<strong>CAPTCHA and a honeypot.</strong> Cloudflare Turnstile, hCaptcha, Google reCAPTCHA or the self-hosted ALTCHA, on WordPress and WooCommerce forms.', 'modify-login'),
                    __('<strong>Two-factor login and passkeys.</strong> Authenticator apps, backup codes, passkeys and hardware keys, set up by each person from their profile.', 'modify-login'),
                    __('<strong>Breached-password check.</strong> Refuse new passwords that appear in known data breaches (opt-in).', 'modify-login'),
                    __('<strong>Hardening.</strong> XML-RPC, application passwords, username discovery, vague login errors and a private-site mode.', 'modify-login'),
                    __('<strong>Activity log.</strong> Logins, failed attempts and lockouts, stored only in your database.', 'modify-login'),
                    __('<strong>Login page designer.</strong> Templates, “Match my site”, and every login screen styled in one place.', 'modify-login'),
                    __('<strong>Leak Check.</strong> Probes your own site from the outside to prove the hidden login address does not leak.', 'modify-login'),
                )) . self::p(
                    sprintf(__('Authlify was called Modify Login before version 3.0. Upgraded sites keep their login address and settings; see %s.', 'modify-login'), self::doc('faq-upgrade-from-modify-login', __('Upgrading from Modify Login', 'modify-login')))
                ),
                array('overview', 'about', 'features', 'modify login')
            ),

            self::a('install', $c, __('Install and activate', 'modify-login'), __('Requirements, installing from WordPress.org, and what happens on activation.', 'modify-login'),
                self::sections(array(
                    __('Requirements', 'modify-login') => self::ul(array(
                        __('WordPress 6.4 or newer.', 'modify-login'),
                        __('PHP 7.4 or newer. Passkeys need PHP 8.0 or newer with the OpenSSL extension; everything else works on 7.4.', 'modify-login'),
                        __('Passkeys also need the site to run on https (browsers only allow them in a secure context).', 'modify-login'),
                    )),
                    __('Install', 'modify-login') => self::ul(array(
                        __('In wp-admin, open <strong>Plugins → Add New</strong>, search for “Authlify”, then click <strong>Install Now</strong> and <strong>Activate</strong>.', 'modify-login'),
                        __('Or upload the plugin zip under <strong>Plugins → Add New → Upload Plugin</strong>.', 'modify-login'),
                        __('Or with WP-CLI:', 'modify-login') . self::code('wp plugin install modify-login --activate'),
                    ), true),
                    __('What activation changes', 'modify-login') => self::p(
                        __('On a new site, nothing about how people log in changes yet: the login page stays at wp-login.php until you choose a new address. Brute-force lockouts, the activity log and the weekly Leak Check are on by default. Two-factor login is available but each person turns it on for themselves. CAPTCHA, the honeypot, the breached-password check and the hardening options are off until you switch them on.', 'modify-login'),
                        __('Authlify creates three database tables (activity log, lockout counters and passkeys) and a daily clean-up task.', 'modify-login'),
                        sprintf(__('On a site upgraded from Modify Login 1.x or 2.x, the old login address, redirects and log carry over, and new protection starts switched off. See %s.', 'modify-login'), self::doc('faq-upgrade-from-modify-login', __('Upgrading from Modify Login', 'modify-login')))
                    ),
                    __('Multisite', 'modify-login') => self::p(
                        __('Network-activate Authlify to manage one set of settings for the whole network from <strong>Network Admin → Authlify</strong>. Only super admins can then change settings.', 'modify-login'),
                        sprintf(__('Read %s before you do.', 'modify-login'), self::doc('trouble-multisite', __('Multisite notes', 'modify-login')))
                    ),
                )),
                array('requirements', 'php', 'download', 'activate', 'setup')
            ),

            self::a('first-run', $c, __('First-run setup', 'modify-login'), __('What you see after activating, and the one step that matters most.', 'modify-login'),
                self::p(
                    __('After activation a notice says <strong>“Authlify is active. Nothing is hidden yet.”</strong> on the Plugins screen, the WordPress dashboard and the Authlify dashboard. Its button, <strong>Choose my login URL</strong>, opens the Login URL screen.', 'modify-login'),
                    __('The notice disappears once you save a new login address, even before you confirm it.', 'modify-login')
                ) . self::sections(array(
                    __('Choose your login address', 'modify-login') => self::ul(array(
                        sprintf(__('Open %s.', 'modify-login'), self::go('authlify-login-url', __('Authlify → Login URL', 'modify-login'))),
                        __('Type a new address under <strong>New login address</strong>, or use the suggestion shown under the field, and click <strong>Save changes</strong>.', 'modify-login'),
                        __('A notice asks you to confirm. Click <strong>Open and confirm the new URL</strong>. It opens in a new tab and switches the address on.', 'modify-login'),
                        __('Bookmark the new address. We also email it to the site admin address and to you.', 'modify-login'),
                    ), true) . self::note(__('Until you confirm, both the old and the new address work, so a typo cannot lock you out. An unconfirmed change expires after 30 minutes and the old address stays.', 'modify-login')),
                    __('Then check the dashboard', 'modify-login') => self::p(
                        sprintf(__('%s shows a checklist of protections, with the next one to do highlighted. Leak Check runs on its own a few seconds after the login address changes and tells you whether the address can be discovered.', 'modify-login'), self::go('modify-login', __('Authlify → Dashboard', 'modify-login')))
                    ),
                )),
                array('onboarding', 'notice', 'setup', 'wizard', 'start')
            ),

            self::a('recommended-setup', $c, __('Recommended setup', 'modify-login'), __('Ten minutes of settings that stop almost every automated attack without bothering real people.', 'modify-login'),
                self::ul(array(
                    sprintf(__('<strong>Login URL.</strong> Choose a private address and confirm it. Keep %s on the recommended “page not found” response.', 'modify-login'), '<em>' . esc_html__('Show visitors', 'modify-login') . '</em>'),
                    __('<strong>Visitor IP address.</strong> On <strong>Security → Brute force</strong>, check that “Detected setup” says <em>Matches your setting</em>. If your site is behind Cloudflare or a proxy, change the setting first; otherwise lockouts can hit everyone at once.', 'modify-login'),
                    __('<strong>Never lock out.</strong> Add your own fixed office or home IP address, if you have one.', 'modify-login'),
                    __('<strong>Brute force.</strong> Keep the defaults: 5 failed attempts in 15 minutes, a 15-minute lockout, escalating lockouts on.', 'modify-login'),
                    __('<strong>CAPTCHA.</strong> Choose Cloudflare Turnstile (or ALTCHA if nothing may be sent to another company), turn on <em>Test mode</em> for a few days, then turn it off.', 'modify-login'),
                    __('<strong>Hardening.</strong> Set XML-RPC to “On, but block multi-password requests” (or Off if you do not use the mobile app or Jetpack), and turn on <em>Username discovery</em> and <em>Login error messages</em>.', 'modify-login'),
                    __('<strong>Two-factor.</strong> Set it up for your own administrator account from your profile, and save the backup codes somewhere safe.', 'modify-login'),
                    __('<strong>Breached passwords.</strong> Turn it on for administrators and editors.', 'modify-login'),
                    __('<strong>Activity.</strong> Keep 90 days of history, and turn on the lockout email.', 'modify-login'),
                ), true) . self::p(
                    sprintf(__('Each step has its own article under %s, and %s explains every setting with its default.', 'modify-login'), self::doc('login-url', __('Free features', 'modify-login')), self::doc('config-login', __('Configuration', 'modify-login')))
                ),
                array('best practice', 'checklist', 'quick start', 'defaults')
            ),

            self::a('first-week', $c, __('Your first week', 'modify-login'), __('A safe order of work for a live site, and what to check afterwards.', 'modify-login'),
                self::sections(array(
                    __('Day one', 'modify-login') => self::ul(array(
                        __('Keep a second browser (or a private window) logged in while you change the login address, the CAPTCHA or two-factor settings.', 'modify-login'),
                        __('Change the login address and confirm it. Test it in a private window.', 'modify-login'),
                        __('Tell other people who log in about the new address. Links inside WordPress (emails, the admin bar, “Log in” links) update on their own.', 'modify-login'),
                        __('If a page cache is in use, check that the login address is not cached. See the cache article.', 'modify-login'),
                    ), true),
                    __('During the week', 'modify-login') => self::ul(array(
                        __('Run a CAPTCHA in test mode. The Safety panel shows how many submissions would have been blocked in the last 7 days.', 'modify-login'),
                        __('Look at <strong>Activity</strong>. Failed logins against usernames that do not exist are bots; failures on real accounts from one place may be a forgotten password.', 'modify-login'),
                        __('Look at the Leak Check result on the dashboard. “Passed” means none of the known routes reveal your login address.', 'modify-login'),
                    )),
                    __('After a week', 'modify-login') => self::ul(array(
                        __('Turn off CAPTCHA test mode if nobody real would have been blocked.', 'modify-login'),
                        __('Ask everyone with an administrator account to set up two-factor login.', 'modify-login'),
                        __('Export your settings (Authlify → Settings) and keep the file with your site backups.', 'modify-login'),
                    )),
                )) . self::p(sprintf(__('If anything goes wrong, %s lists every way back in.', 'modify-login'), self::doc('howto-locked-out', __('Get back in when locked out', 'modify-login')))),
                array('workflow', 'rollout', 'test', 'live site')
            ),
        );
    }

    /* ----------------------------------------------------------------------
     * Free features
     * ------------------------------------------------------------------- */

    /**
     * Free feature articles.
     *
     * @return array
     */
    private static function feature_articles()
    {
        return array_merge(
            self::feature_login(),
            self::feature_security(),
            self::feature_captcha(),
            self::feature_identity(),
            self::feature_site()
        );
    }

    /**
     * Login URL, recovery, Leak Check.
     *
     * @return array
     */
    private static function feature_login()
    {
        $c = 'features';

        return array(
            self::a('login-url', $c, __('Login URL and hiding', 'modify-login'), __('Move the login page to a private address and hide wp-login.php and /wp-admin/ from visitors who are not logged in.', 'modify-login'),
                self::sections(array(
                    __('Purpose', 'modify-login') => self::p(__('Almost every attack on a WordPress login is a script that posts guesses to wp-login.php. When the login page lives at an address only you know, those scripts get a “page not found” and give up. It is not a replacement for strong passwords or lockouts, but it removes most of the noise.', 'modify-login')),
                    __('Where to find it', 'modify-login') => self::p(sprintf(__('%s. The screen has two tabs: Login URL and Redirects.', 'modify-login'), self::go('authlify-login-url', __('Authlify → Login URL', 'modify-login')))),
                    __('How to configure it', 'modify-login') => '<h4>' . esc_html__('Your login address', 'modify-login') . '</h4>' . self::ul(array(
                        __('<strong>New login address</strong>: 3 to 64 characters of letters, numbers, hyphens and underscores, with at least one letter. It is lowercased.', 'modify-login'),
                        __('Some words are reserved because WordPress uses them: for example <code>login</code>, <code>admin</code>, <code>dashboard</code>, <code>wp-admin</code>, <code>wp-login</code>, <code>register</code>, <code>feed</code>, <code>search</code>, <code>author</code>, <code>sitemap</code> and WordPress query words such as <code>p</code> or <code>s</code>. The address also cannot match an existing page or post.', 'modify-login'),
                        __('Leave the field empty to go back to the standard wp-login.php page. That applies immediately.', 'modify-login'),
                    )) . '<h4>' . esc_html__('Hide the default login', 'modify-login') . '</h4>' . self::ul(array(
                        __('<strong>Hide default URLs</strong> (on by default) hides wp-login.php and wp-admin from visitors. It needs a custom login address.', 'modify-login'),
                        __('<strong>Show visitors</strong>: your theme’s “page not found” page (recommended), an “access denied” message, or a redirect.', 'modify-login'),
                        __('<strong>Redirect to</strong>: used with the redirect option. Use an address on this site; leave it empty for the homepage.', 'modify-login'),
                    )),
                    __('How to use it', 'modify-login') => self::ul(array(
                        __('After you save a new address, click <strong>Open and confirm the new URL</strong> in the notice. The change only takes effect once you open that link while logged in as an administrator.', 'modify-login'),
                        __('Until then both the old and the new address work. After 30 minutes an unconfirmed change expires and nothing changes. <strong>Cancel the change</strong> ends it early.', 'modify-login'),
                        __('Once confirmed, the new address is emailed to the site admin address and to you.', 'modify-login'),
                    ), true),
                    __('What to expect', 'modify-login') => self::ul(array(
                        __('The login page answers at <code>https://example.com/your-address/</code> (and at <code>?your-address</code>). It is never cached and tells search engines not to index it.', 'modify-login'),
                        __('Visitors who are not logged in get the chosen response for wp-login.php (including disguised variants such as <code>//wp-login.php</code> or URL-encoded names), wp-register.php, and any page under /wp-admin/.', 'modify-login'),
                        __('Inside /wp-admin/, the “page not found” response is a redirect to <code>/404/</code> on your site.', 'modify-login'),
                        __('The WordPress shortcuts <code>/login</code>, <code>/admin</code> and <code>/dashboard</code> stop working for visitors who are not logged in.', 'modify-login'),
                        __('WordPress links to wp-login.php (the lost-password link, “Log in” links, emails) point to your new address automatically.', 'modify-login'),
                        __('admin-ajax.php and admin-post.php stay reachable, so front-end forms keep working. Password-protected posts keep working too.', 'modify-login'),
                        __('Logged-in people opening the login address go straight to the dashboard (or the redirect you set). wp-login.php itself shows the blocked response to everyone, logged in or not, so always use your own address.', 'modify-login'),
                    )),
                    __('Limitations', 'modify-login') => self::ul(array(
                        __('Hiding the login page is not a security boundary on its own. Keep brute-force lockouts on.', 'modify-login'),
                        __('On a private site (Force login), visitors are sent to your login address, so it is no longer secret.', 'modify-login'),
                        __('WooCommerce’s My Account login form and other front-end login forms are not hidden; they are protected by lockouts and CAPTCHA instead.', 'modify-login'),
                        __('On multisite, wp-signup.php and wp-activate.php are not hidden because the network needs them.', 'modify-login'),
                        __('Do not run a second “hide login” plugin at the same time. Authlify warns you if it finds one.', 'modify-login'),
                    )),
                )) . self::p(sprintf(__('Related: %1$s, %2$s, %3$s.', 'modify-login'), self::doc('login-recovery', __('Getting back in', 'modify-login')), self::doc('leak-check', __('Leak Check', 'modify-login')), self::doc('howto-hide-login', __('How to hide your login page safely', 'modify-login')))),
                array('hide login', 'custom login url', 'slug', 'wp-login.php', 'wp-admin', '404', 'rename login', 'secret')
            ),

            self::a('login-recovery', $c, __('Getting back in (recovery)', 'modify-login'), __('Every way to recover a forgotten login address or get past a lockout, without FTP support tickets.', 'modify-login'),
                self::sections(array(
                    __('Forgot the login address', 'modify-login') => self::ul(array(
                        __('<strong>Email.</strong> Each time the address changes, Authlify emails it to the site admin address and to the person who changed it. Search your mail for “Your login address”. While logged in, <strong>Login URL → If you ever lose the login URL → Email it to me now</strong> sends it again.', 'modify-login'),
                        __('<strong>wp-config.php.</strong> Add this line above “That’s all, stop editing!”. The custom address is switched off and wp-login.php works again. Remove the line afterwards.', 'modify-login') . self::code("define( 'AUTHLIFY_DISABLE_HIDE', true );"),
                        __('<strong>WP-CLI.</strong> Show the address, or turn the custom address off:', 'modify-login') . self::code("wp authlify url get\nwp authlify url reset"),
                    )),
                    __('Force a known address', 'modify-login') => self::p(__('To set the address from wp-config.php instead (for example on a staging copy), define <code>AUTHLIFY_SLUG</code>. It must be 3 to 64 lowercase letters, numbers, hyphens or underscores and not a reserved word; an invalid value is ignored. While the constant is set, the Login URL field is locked and <code>wp authlify url set</code> refuses to run.', 'modify-login')) . self::code("define( 'AUTHLIFY_SLUG', 'my-private-door' );") . self::note(__('If both constants are set, <code>AUTHLIFY_DISABLE_HIDE</code> wins. <code>wp authlify url reset</code> cannot override <code>AUTHLIFY_SLUG</code>; remove the constant instead.', 'modify-login')),
                    __('Locked out after wrong passwords', 'modify-login') => self::ul(array(
                        __('Wait: the lockout message says how long. The first lockout lasts 15 minutes by default.', 'modify-login'),
                        __('Click <strong>“Is this your account? Email me an unlock link.”</strong> under the lockout message. The link arrives at the account’s own email address and works for 30 minutes. It lets that one account log in from the same IP address; the lockout for everyone else stays. At most three requests per hour per address.', 'modify-login'),
                        __('Another administrator can unlock you under <strong>Security → Brute force → Locked out right now</strong>.', 'modify-login'),
                        __('With WP-CLI:', 'modify-login') . self::code("wp authlify lockouts\nwp authlify unlock 203.0.113.7\nwp authlify unlock --all"),
                    )),
                    __('Blocked by CAPTCHA or two-factor', 'modify-login') => self::p(sprintf(__('See %1$s and %2$s. Both have an emergency switch in wp-config.php.', 'modify-login'), self::doc('captcha', __('CAPTCHA', 'modify-login')), self::doc('twofa-recovery', __('Two-factor recovery', 'modify-login')))),
                )),
                array('lost url', 'forgot', 'locked out', 'recovery', 'AUTHLIFY_DISABLE_HIDE', 'AUTHLIFY_SLUG', 'wp-config', 'unlock', 'emergency')
            ),

            self::a('leak-check', $c, __('Leak Check', 'modify-login'), __('An automatic test that visits your site as a stranger would and proves the hidden login address is not revealed anywhere.', 'modify-login'),
                self::sections(array(
                    __('Purpose', 'modify-login') => self::p(__('Many “hide login” plugins can be found in one request: a password-reset link, a redirect from /wp-admin/ or a theme link gives the secret address away. Leak Check tries those routes against your own site and reports what it finds.', 'modify-login')),
                    __('Where to find it', 'modify-login') => self::p(sprintf(__('The Leak Check panel on the %1$s, a status line on the %2$s screen, a dashboard checklist item, WordPress Site Health, and the <code>wp authlify leak-check</code> command.', 'modify-login'), self::go('modify-login', __('Dashboard', 'modify-login')), self::go('authlify-login-url', __('Login URL', 'modify-login')))),
                    __('How it works', 'modify-login') => self::ul(array(
                        __('It only sends requests to your own site’s address, logged out, without following redirects and without ever submitting a password. Each request carries a signed header, so the probes are not written to the activity log.', 'modify-login'),
                        __('It checks that the login address works and is not cached, then tries about forty routes: wp-login.php and disguised variants, every login action (register, lost password, logout and others), /wp-admin/ pages, the /login, /admin and /dashboard shortcuts, old Modify Login back doors, and public pages such as the homepage, a post, a 404 page, robots.txt, the sitemap, the feed and the REST index.', 'modify-login'),
                        __('A route leaks if it shows the login form, or mentions the address in a redirect or in the page.', 'modify-login'),
                        __('It also visits front-end login pages: /login/, /my-account/, /account/, /members/ and /register/, the login, account and registration pages of WooCommerce, Easy Digital Downloads, Paid Memberships Pro, Ultimate Member and BuddyPress, the WooCommerce cart and checkout, and pages with a login form or an Authlify block. A login form on a public page has to send people to your login address, so anyone who opens that page can read it: this is reported as a warning, not a leak.', 'modify-login'),
                        __('It also reports, as warnings, things that help attackers without revealing the address: XML-RPC multi-password requests, usernames in the REST API, and <code>?author=</code> scans.', 'modify-login'),
                    )),
                    __('When it runs', 'modify-login') => self::ul(array(
                        __('About ten seconds after the login address or hiding settings change.', 'modify-login'),
                        __('Once a week, on the daily maintenance task.', 'modify-login'),
                        __('When you click <strong>Run Leak Check</strong> (at most once every 30 seconds), or run the WP-CLI command.', 'modify-login'),
                        __('It does nothing while no custom login address is set.', 'modify-login'),
                    )),
                    __('Reading the result', 'modify-login') => '<dl><dt>' . esc_html__('Passed', 'modify-login') . '</dt><dd>' . esc_html__('No route revealed the login address. Warnings about XML-RPC, REST usernames or author scans can still be listed; fix them on the Hardening tab.', 'modify-login') . '</dd>'
                        . '<dt>' . esc_html__('Warning', 'modify-login') . '</dt><dd>' . esc_html__('No hidden route leaked, but a public login page (a front-end login form) shows the address, or wp-login.php is not hidden. Keep the page if you want it: brute-force limits, CAPTCHA and two-factor login still protect the login. A result older than 14 days is also marked out of date in Site Health.', 'modify-login') . '</dd>'
                        . '<dt>' . esc_html__('Failed', 'modify-login') . '</dt><dd>' . esc_html__('At least one route revealed the address. Each result names the route and how to fix it. A theme or another plugin that prints wp_login_url() on public pages is the usual cause.', 'modify-login') . '</dd>'
                        . '<dt>' . esc_html__('Incomplete or untested', 'modify-login') . '</dt><dd>' . esc_html__('Your server could not reach itself (a “loopback” request). Some hosts block this; Site Health reports the same problem.', 'modify-login') . '</dd></dl>',
                    __('Limitations', 'modify-login') => self::p(__('Leak Check tests known routes on your own site. It cannot see links to your login address posted elsewhere, and it does not test pages behind a login.', 'modify-login')),
                )),
                array('leak', 'probe', 'scan', 'test hidden login', 'site health', 'loopback')
            ),
        );
    }

    /**
     * Brute force, IP, lists, hardening, force login.
     *
     * @return array
     */
    private static function feature_security()
    {
        $c = 'features';
        $brute = self::go('authlify-protection', __('Authlify → Security → Brute force', 'modify-login'), array('tab' => 'limits'));
        $hardening = self::go('authlify-protection', __('Authlify → Security → Hardening', 'modify-login'), array('tab' => 'hardening'));

        return array(
            self::a('brute-force', $c, __('Brute-force protection', 'modify-login'), __('Lock out addresses that keep guessing passwords, with lockouts that grow longer for repeat offenders.', 'modify-login'),
                self::sections(array(
                    __('Purpose', 'modify-login') => self::p(__('A password-guessing script tries thousands of passwords. Limiting failed attempts per IP address makes that impossible, while everyone else keeps logging in as normal.', 'modify-login')),
                    __('Where to find it', 'modify-login') => self::p($brute),
                    __('How to configure it', 'modify-login') => self::ul(array(
                        __('<strong>Brute-force protection</strong>: on by default on new sites.', 'modify-login'),
                        __('<strong>Failed attempts allowed</strong> (default 5) within the <strong>Time window</strong> (default 15 minutes), per IP address.', 'modify-login'),
                        __('<strong>Lockout length</strong> (default 15 minutes).', 'modify-login'),
                        __('<strong>Escalating lockouts</strong> (on by default): repeat lockouts last 1×, 4×, 16× and then 96× the lockout length. With the default 15 minutes that is 15 minutes, 1 hour, 4 hours, then 24 hours. The count starts again once an address has had no lockout for 24 hours.', 'modify-login'),
                        __('<strong>Network lockouts</strong> (off by default): when addresses from one network (an IPv4 /24 or IPv6 /48) fail three times the allowed attempts in total, the whole network is locked. It stops attackers who rotate addresses, but it can also lock out an office or a mobile carrier, so add your own address to “Never lock out” first.', 'modify-login'),
                        __('<strong>Targeted-account threshold</strong> (default 10): when one username collects this many failures from different addresses, the account is not locked out. Instead, if a CAPTCHA is set to “Only after failed logins”, the login form asks for it for that username.', 'modify-login'),
                        __('<strong>Account pause</strong> (on by default): at twice the targeted-account threshold, the account is paused for addresses it has never logged in from, for the lockout length. The owner still logs in from any address they used before or on “Never lock out”, and the lockout message offers an unlock email.', 'modify-login'),
                        __('An IPv6 visitor is counted by their /64, because one connection usually owns a whole /64.', 'modify-login'),
                    )),
                    __('What to expect', 'modify-login') => self::ul(array(
                        __('When one or two attempts remain, the error says so. (This hint is hidden when “Login error messages” is on.)', 'modify-login'),
                        __('A locked-out visitor sees “Too many failed login attempts. Please try again in N minutes”, with a link to email themselves an unlock link.', 'modify-login'),
                        __('The lock applies to the address, for every account, administrators included. People already logged in are not affected.', 'modify-login'),
                        __('Logins through XML-RPC, the REST API and application passwords are counted and locked too.', 'modify-login'),
                        __('A successful login clears that address’s failure count, but not an active lockout.', 'modify-login'),
                        __('Current lockouts are listed under <strong>Locked out right now</strong>, with <strong>Unlock</strong> and <strong>Unlock everyone</strong>. Unlocking an address also clears its network.', 'modify-login'),
                        __('Old counters are cleaned up daily.', 'modify-login'),
                    )),
                    __('Limitations', 'modify-login') => self::ul(array(
                        __('Lockouts only work if Authlify sees each visitor’s real IP address. Behind Cloudflare or a proxy, set the visitor IP option first.', 'modify-login'),
                        __('The allow and block lists only apply while brute-force protection is on.', 'modify-login'),
                        __('On multisite the counters and lockouts are shared by the whole network.', 'modify-login'),
                    )),
                )) . self::p(sprintf(__('Related: %1$s, %2$s.', 'modify-login'), self::doc('ip-detection', __('Visitor IP address', 'modify-login')), self::doc('allow-block-lists', __('Allow and block lists', 'modify-login')))),
                array('limit login attempts', 'lockout', 'escalation', 'network lockout', 'targeted account', 'attempts', 'bruteforce')
            ),

            self::a('ip-detection', $c, __('Visitor IP address', 'modify-login'), __('Tell Authlify how visitors reach your site, so lockouts hit the attacker and not your CDN.', 'modify-login'),
                self::sections(array(
                    __('Purpose', 'modify-login') => self::p(__('Behind Cloudflare, a load balancer or a reverse proxy, every request seems to come from the proxy. If Authlify locked that address, it would lock out everyone. Forwarding headers carry the real address, but anyone can fake them, so Authlify only reads them from proxies it can trust.', 'modify-login')),
                    __('Where to find it', 'modify-login') => self::p(sprintf(__('%s, panel <strong>Visitor IP address</strong>.', 'modify-login'), $brute)),
                    __('How to configure it', 'modify-login') => '<dl><dt>' . esc_html__('Detected setup', 'modify-login') . '</dt><dd>' . esc_html__('A suggestion based on your own current request, with “Matches your setting” or “Recommended: change the setting below”, and the IP address Authlify sees for you right now. It never changes the setting by itself.', 'modify-login') . '</dd>'
                        . '<dt>' . esc_html__('Directly (default)', 'modify-login') . '</dt><dd>' . esc_html__('Uses the connecting address. Right for sites with no proxy or CDN in front.', 'modify-login') . '</dd>'
                        . '<dt>' . esc_html__('Through Cloudflare', 'modify-login') . '</dt><dd>' . wp_kses(__('Uses the <code>CF-Connecting-IP</code> header, but only when the request really comes from one of Cloudflare’s published address ranges.', 'modify-login'), array('code' => array())) . '</dd>'
                        . '<dt>' . esc_html__('Through my own proxy or load balancer', 'modify-login') . '</dt><dd>' . wp_kses(__('Uses <code>X-Forwarded-For</code>, but only when the request comes from an address in <strong>Trusted proxies</strong> (one IP or CIDR range per line). It reads the header from the right and takes the first address that is not one of your proxies.', 'modify-login'), array('code' => array(), 'strong' => array())) . '</dd></dl>',
                    __('What to expect', 'modify-login') => self::p(__('After saving, “Your IP address as seen now” should show your real public address, not your host’s or Cloudflare’s. WordPress Site Health also warns when the setting and your setup disagree.', 'modify-login')),
                    __('Limitations', 'modify-login') => self::ul(array(
                        __('The Cloudflare address ranges are built into the plugin. Cloudflare rarely changes them, but a request from a brand-new range would be treated as direct.', 'modify-login'),
                        __('The <code>X-Real-IP</code> header is not used.', 'modify-login'),
                    )),
                )),
                array('cloudflare', 'proxy', 'load balancer', 'x-forwarded-for', 'cf-connecting-ip', 'remote_addr', 'trusted proxies', 'cdn')
            ),

            self::a('allow-block-lists', $c, __('Allow and block lists', 'modify-login'), __('Addresses that are never locked out, and addresses that can never log in.', 'modify-login'),
                self::sections(array(
                    __('Where to find it', 'modify-login') => self::p(sprintf(__('%s, panel <strong>Allow and block lists</strong>.', 'modify-login'), $brute)),
                    __('How to configure it', 'modify-login') => self::ul(array(
                        __('<strong>Never lock out</strong>: your office or home IP. Failures from these addresses are not counted, they are never locked out, and they never see a CAPTCHA.', 'modify-login'),
                        __('<strong>Always block</strong>: these addresses can never log in. They get “Access from your network is blocked.” They can still view the site.', 'modify-login'),
                        __('One IPv4 or IPv6 address or CIDR range per line (commas also work), for example <code>203.0.113.7</code>, <code>198.51.100.0/24</code> or <code>2001:db8::/32</code>. Invalid entries are refused when you save.', 'modify-login'),
                        __('You cannot save a block list that contains your own current address.', 'modify-login'),
                        __('<strong>Block in one click</strong>: on Authlify → Activity, each address has a <strong>Block</strong> link, and each row of <strong>Locked out right now</strong> has a <strong>Block</strong> button (<strong>Block network</strong> for a network lockout). It adds the address to Always block after you confirm, and is recorded in the activity log as “Added to block list”. It is not offered for your own current address, private or local addresses, addresses already on either list, or when the log stores anonymized addresses.', 'modify-login'),
                        __('<strong>Block repeat offenders</strong> (0, off, by default): after this many lockouts of one address in a row, the address is added to Always block. A day without a lockout starts the count again. Addresses an administrator has logged in from, and private or local addresses, are never added; such skips are logged as “Automatic block skipped”. It stops adding once the list holds 2,000 lines.', 'modify-login'),
                    )),
                    __('Limitations', 'modify-login') => self::p(__('Both lists apply even when lockouts are switched off. They use the address from the Visitor IP setting, so set that first. Blocking is permanent until you remove the line from Always block.', 'modify-login')),
                )),
                array('whitelist', 'blacklist', 'allowlist', 'denylist', 'cidr', 'block ip', 'never lock out', 'ban', 'permanent', 'repeat offenders')
            ),

            self::a('hardening', $c, __('Hardening', 'modify-login'), __('Close the other ways into WordPress: XML-RPC, application passwords, username discovery and revealing error messages.', 'modify-login'),
                self::p(sprintf(__('Where: %s. Every option here is off (the WordPress default) until you change it.', 'modify-login'), $hardening))
                . self::sections(array(
                    __('XML-RPC', 'modify-login') => self::ul(array(
                        __('<strong>On</strong> (default): unchanged. Some apps and Jetpack need it.', 'modify-login'),
                        __('<strong>On, but block multi-password requests</strong>: removes <code>system.multicall</code>, which lets one request try hundreds of passwords. Such requests get an error (HTTP 403). Everything else keeps working.', 'modify-login'),
                        __('<strong>Off</strong>: every request to xmlrpc.php gets HTTP 403, and the pingback header and discovery link are removed. Choose it if you do not use the WordPress mobile app, Jetpack or remote publishing.', 'modify-login'),
                    )),
                    __('Application passwords', 'modify-login') => self::p(__('They let apps log in through the REST API without the login page, CAPTCHA or two-factor. Choose <strong>All users</strong> (default), <strong>Administrators only</strong> (on multisite: super admins only) or <strong>Off</strong>. Brute-force limits apply to them either way.', 'modify-login')),
                    __('Username discovery', 'modify-login') => self::p(__('When on, visitors who are not logged in can no longer list usernames: the REST API users endpoints disappear, <code>?author=</code> links and author archives return “page not found”, the users sitemap is removed and oEmbed data no longer names the author. Logged-in people still see author archives.', 'modify-login')),
                    __('Login error messages', 'modify-login') => self::p(__('When on, a failed login always says “The username or password is incorrect.” instead of telling an attacker which one was wrong.', 'modify-login')),
                    __('Private site', 'modify-login') => self::p(sprintf(__('See %s.', 'modify-login'), self::doc('force-login', __('Force login (private site)', 'modify-login')))),
                )),
                array('xmlrpc', 'xml-rpc', 'multicall', 'application passwords', 'user enumeration', 'author scan', 'error message', 'rest api users')
            ),

            self::a('force-login', $c, __('Force login (private site)', 'modify-login'), __('Only logged-in users can see the site; everyone else is sent to the login page.', 'modify-login'),
                self::sections(array(
                    __('Where to find it', 'modify-login') => self::p(sprintf(__('%s, panel <strong>Private site</strong>.', 'modify-login'), $hardening)),
                    __('How to configure it', 'modify-login') => self::ul(array(
                        __('<strong>Force login</strong>: “Require login to view the site” (off by default).', 'modify-login'),
                        __('<strong>Public pages</strong>: paths that stay public, one per line (commas also work). A path includes its sub-pages: <code>/shop</code> covers <code>/shop/</code> and <code>/shop/item</code>, but not <code>/shopping</code>. Use <code>/</code> for the home page only. Full URLs are accepted; only the path is used. There are no wildcards.', 'modify-login'),
                    )),
                    __('What to expect', 'modify-login') => self::ul(array(
                        __('Visitors who are not logged in are redirected to your login address, and come back to the page they wanted after logging in.', 'modify-login'),
                        __('Anonymous REST API requests get a “You must be logged in to use this site.” error (HTTP 401).', 'modify-login'),
                        __('The login page itself, robots.txt, admin-ajax and scheduled tasks keep working. RSS feeds are redirected too.', 'modify-login'),
                    )),
                    __('Limitations', 'modify-login') => self::ul(array(
                        __('Because every visitor is sent to the login page, the custom login address is no longer secret on a private site. Leak Check reports those redirects as "Info", not as leaks.', 'modify-login'),
                        __('XML-RPC is not affected. Set it to Off on the same tab if you do not need it.', 'modify-login'),
                        __('Pages that other services must reach (payment callbacks, webhooks, a WooCommerce shop) need a Public pages entry.', 'modify-login'),
                    )),
                )),
                array('private site', 'members only', 'intranet', 'require login', 'public pages', 'exclude')
            ),
        );
    }

    /**
     * CAPTCHA and honeypot.
     *
     * @return array
     */
    private static function feature_captcha()
    {
        $c = 'features';
        $tab = self::go('authlify-protection', __('Authlify → Security → CAPTCHA', 'modify-login'), array('tab' => 'captcha'));

        return array(
            self::a('captcha', $c, __('CAPTCHA, modes and safety', 'modify-login'), __('Stop scripts at the form, choose when people see the check, and decide what happens if the provider is down.', 'modify-login'),
                self::sections(array(
                    __('Purpose', 'modify-login') => self::p(__('A CAPTCHA stops scripts that guess passwords, create fake accounts or post spam before they reach WordPress. Lockouts react after failures; a CAPTCHA stops many attempts from counting at all.', 'modify-login')),
                    __('Where to find it', 'modify-login') => self::p(sprintf(__('%s, with three panels: CAPTCHA provider, Where and when, and Safety.', 'modify-login'), $tab)),
                    __('Forms', 'modify-login') => self::p(__('Login, Registration and Lost password are ticked by default once a provider is chosen. You can also protect comments from visitors who are not logged in, and, when WooCommerce is active, its login, registration, lost password and checkout forms. The checkout switch covers guest orders on both the classic checkout and the Checkout block; with only WooCommerce registration ticked, the Checkout block asks for the CAPTCHA only when the order creates an account.', 'modify-login'))
                        . self::p(__('Other plugins’ forms follow the same switches while the plugin is active: the login forms of Easy Digital Downloads, Ultimate Member and MemberPress follow <strong>Login</strong>; the sign-up forms of Easy Digital Downloads, Ultimate Member and BuddyPress or BuddyBoss follow <strong>Registration</strong>; the lost-password forms of Easy Digital Downloads (block) and Ultimate Member follow <strong>Lost password</strong>. The panel lists the plugins it found. MemberPress support is built on its documented hooks and has not been tested against MemberPress itself.', 'modify-login'))
                        . self::p(__('On every login form the CAPTCHA is checked before the password, so a bot that leaves it out is refused without a password check. Lockouts, the block list and two-factor login apply to every login that goes through WordPress, whichever form it comes from; Ultimate Member and Easy Digital Downloads show the lockout message instead of their own “wrong password” text.', 'modify-login')) . self::p(__('Logins through XML-RPC, the REST API and application passwords cannot show a CAPTCHA; the brute-force limits cover those.', 'modify-login')),
                    __('When to show it', 'modify-login') => '<dl><dt>' . esc_html__('Always (default)', 'modify-login') . '</dt><dd>' . esc_html__('Every protected form shows the check.', 'modify-login') . '</dd>'
                        . '<dt>' . esc_html__('Only after failed logins', 'modify-login') . '</dt><dd>' . esc_html__('The WordPress and WooCommerce login forms show it only after “Failed logins before it appears” (default 2) failures from the visitor’s address, or when the username is under attack from many addresses (the targeted-account threshold on the Brute force tab). Other forms always show it. Honest visitors who type their password correctly never see it.', 'modify-login') . '</dd></dl>',
                    __('Safety', 'modify-login') => self::ul(array(
                        __('<strong>Test mode</strong> (off by default): the widget is shown and every answer is checked, but failures are only logged; nobody is blocked. This also applies to the honeypot. The panel then counts how many submissions would have been blocked in the last 7 days (this needs the activity log to be on).', 'modify-login'),
                        __('<strong>If the provider is down</strong>: “Let people through” (default; the brute-force limits still apply) or “Block the form until the provider is back”. A provider counts as down when it cannot be reached within 8 seconds or returns a server error. Outages are recorded in the activity log.', 'modify-login'),
                        __('<strong>Honeypot</strong>: see the honeypot article.', 'modify-login'),
                    )),
                    __('What to expect', 'modify-login') => self::ul(array(
                        __('The provider’s script loads only on pages that show a protected form.', 'modify-login'),
                        __('A failed check shows an error on the form and is logged as “CAPTCHA failed” with the reason. Failed CAPTCHA checks do not count towards lockouts.', 'modify-login'),
                        __('Addresses on “Never lock out” never see a CAPTCHA or the honeypot. Logged-in people never see one on comments or checkout.', 'modify-login'),
                        __('The dashboard counts CAPTCHA as done when a provider is set up for the login form and test mode is off.', 'modify-login'),
                    )),
                    __('Emergency switch', 'modify-login') => self::p(__('If a CAPTCHA ever stops you logging in, add this to wp-config.php, log in and fix the settings, then remove it:', 'modify-login')) . self::code("define( 'AUTHLIFY_DISABLE_CAPTCHA', true );"),
                )) . self::p(sprintf(__('Related: %1$s, %2$s.', 'modify-login'), self::doc('captcha-providers', __('CAPTCHA providers', 'modify-login')), self::doc('howto-captcha-safely', __('Add a CAPTCHA without blocking real people', 'modify-login')))),
                array('captcha', 'recaptcha', 'turnstile', 'hcaptcha', 'altcha', 'test mode', 'outage', 'fail open', 'after failures', 'woocommerce checkout', 'comments', 'block checkout', 'easy digital downloads', 'edd', 'ultimate member', 'memberpress', 'buddypress', 'buddyboss')
            ),

            self::a('captcha-providers', $c, __('CAPTCHA providers', 'modify-login'), __('Cloudflare Turnstile, ALTCHA, hCaptcha and Google reCAPTCHA v2 and v3: what each needs and what it sends where.', 'modify-login'),
                self::sections(array(
                    __('Choosing', 'modify-login') => self::p(__('Turnstile suits most sites. Choose ALTCHA if nothing may be sent to another company. You can switch at any time.', 'modify-login')),
                    __('Cloudflare Turnstile (recommended)', 'modify-login') => self::p(__('Free and usually shows no puzzle at all. It does not need Cloudflare hosting. Create a widget under Cloudflare dashboard → Turnstile and paste its site key and secret key. The visitor’s browser loads the widget from challenges.cloudflare.com, and your server confirms each answer there.', 'modify-login')),
                    __('ALTCHA (no third party)', 'modify-login') => self::p(__('Runs entirely on your site: no keys, no cookies and nothing sent elsewhere. The browser solves a small proof-of-work puzzle in the background, and your server checks it; each answer works once. It needs JavaScript, and it cannot have an outage.', 'modify-login')),
                    __('hCaptcha', 'modify-login') => self::p(__('Privacy-focused with a free plan; it shows image puzzles more often than Turnstile. Keys come from the hCaptcha dashboard → Sites. The widget loads from js.hcaptcha.com and answers are checked at api.hcaptcha.com.', 'modify-login')),
                    __('Google reCAPTCHA v2', 'modify-login') => self::p(__('The “I’m not a robot” checkbox. Keys come from the Google reCAPTCHA admin console; pick the v2 checkbox type. It sends visitor data to Google, and free-tier limits apply.', 'modify-login')),
                    __('Google reCAPTCHA v3', 'modify-login') => self::p(__('Invisible: Google scores each visit from 0.0 (bot) to 1.0 (human), and visits below the <strong>Minimum score</strong> (default 0.5) are refused. It can quietly block real people, with no puzzle to prove otherwise. v2 keys do not work with v3.', 'modify-login')),
                    __('Saving keys safely', 'modify-login') => self::ul(array(
                        __('Paste both keys, click <strong>Show preview</strong> and complete the check. The provider’s script loads only when you click.', 'modify-login'),
                        __('When you save, Authlify checks the secret key with the provider using that preview answer. A wrong key is refused and nothing is saved, so a typo cannot lock anyone out.', 'modify-login'),
                        __('Saving without completing the preview is refused, unless test mode is on (then it saves with a warning). If the provider cannot be reached, the keys are saved with a warning.', 'modify-login'),
                        __('Answers solved on another website are refused: the provider must confirm your site’s own domain.', 'modify-login'),
                    )),
                )),
                array('turnstile', 'cloudflare', 'altcha', 'hcaptcha', 'recaptcha', 'google', 'site key', 'secret key', 'score', 'threshold', 'proof of work')
            ),

            self::a('honeypot', $c, __('Honeypot', 'modify-login'), __('An invisible trap that catches simple bots without asking people anything.', 'modify-login'),
                self::sections(array(
                    __('Where to find it', 'modify-login') => self::p(sprintf(__('%s, panel <strong>Safety</strong>, <strong>Honeypot</strong> (off by default).', 'modify-login'), $tab)),
                    __('How it works', 'modify-login') => self::ul(array(
                        __('It adds a hidden field to the same forms the CAPTCHA protects. People, screen readers and password managers never see it; bots that fill in every field do.', 'modify-login'),
                        __('It also adds a signed time stamp. A form sent back in under 2 seconds, with a missing or altered stamp, with a stamp older than a day, or with a stamp already used, is refused.', 'modify-login'),
                        __('It works with or without a CAPTCHA provider.', 'modify-login'),
                    )),
                    __('Limitations', 'modify-login') => self::p(__('A honeypot stops simple scripts, not determined attackers. Use it together with lockouts, and a CAPTCHA where spam is a problem.', 'modify-login')),
                )),
                array('honeypot', 'bot trap', 'spam', 'invisible', 'hidden field')
            ),
        );
    }

    /**
     * Breached passwords, two-factor, passkeys, recovery, designer.
     *
     * @return array
     */
    private static function feature_identity()
    {
        $c = 'features';
        $twofa = self::go('authlify-two-factor', __('Authlify → Two-factor', 'modify-login'));

        return array(
            self::a('breached-passwords', $c, __('Breached-password check', 'modify-login'), __('Refuse new passwords that appear in known data breaches, without ever sending the password anywhere.', 'modify-login'),
                self::sections(array(
                    __('Where to find it', 'modify-login') => self::p(sprintf(__('%s, panel <strong>Breached passwords</strong>. Off by default.', 'modify-login'), self::go('authlify-protection', __('Authlify → Security → Passwords', 'modify-login'), array('tab' => 'passwords')))),
                    __('How to configure it', 'modify-login') => self::ul(array(
                        __('<strong>Breached-password check</strong>: “Refuse passwords found in known data breaches”.', 'modify-login'),
                        __('<strong>Roles</strong>: tick the roles to check. Leave all unticked to check everyone.', 'modify-login'),
                    )),
                    __('When it runs', 'modify-login') => self::p(__('Whenever a password is set: on profile screens (your own, editing a user, adding a user), password resets, registration forms that ask for a password, and WooCommerce registration, account details and checkout account creation. It does not run at login, so existing passwords keep working.', 'modify-login')),
                    __('How the password stays private', 'modify-login') => self::p(__('Authlify hashes the password with SHA-1 and sends only the first 5 characters of that hash to the Have I Been Pwned “range” service (api.pwnedpasswords.com). The service answers with every breached hash starting with those characters, padded with decoys, and Authlify compares the rest on your server. Neither the password nor its full hash leaves your site. Answers are cached for a day.', 'modify-login')),
                    __('What to expect', 'modify-login') => self::p(__('A breached password is refused with “This password has appeared N times in known data breaches, so attackers try it early. Please choose a different password.” and logged as “Breached password refused”. If the service cannot be reached within 3 seconds, the password is accepted.', 'modify-login')),
                )),
                array('hibp', 'have i been pwned', 'pwned', 'breach', 'leaked password', 'k-anonymity', 'password strength')
            ),

            self::a('two-factor', $c, __('Two-factor login', 'modify-login'), __('A second step after the password: an authenticator app, a passkey or a backup code.', 'modify-login'),
                self::sections(array(
                    __('Purpose', 'modify-login') => self::p(__('A stolen or guessed password is not enough to get in when the account also needs a code from the owner’s phone or a passkey on their device.', 'modify-login')),
                    __('Where to find it', 'modify-login') => self::p(sprintf(__('Settings: %s. Each person sets it up under <strong>Users → Profile → Two-factor login</strong>.', 'modify-login'), $twofa)),
                    __('How to configure it', 'modify-login') => self::ul(array(
                        __('<strong>Two-factor login</strong> (on by default): lets people turn it on. When off, nobody is asked for a second step and the profile section is hidden; existing setups are kept.', 'modify-login'),
                        __('<strong>Offered methods</strong>: Authenticator app, Backup codes, Passkeys and security keys. Turning a method off stops new setups; people who already use it keep it.', 'modify-login'),
                        __('<strong>Passkey sign-in</strong>: see the passkeys article.', 'modify-login'),
                    )),
                    __('Setting it up (each person)', 'modify-login') => self::ul(array(
                        __('<strong>Authenticator app</strong>: click “Set up an authenticator app”, scan the QR code with Google Authenticator, 1Password, Authy or a similar app, enter the 6-digit code and click “Verify and turn on”.', 'modify-login'),
                        __('<strong>Backup codes</strong>: 10 one-time codes are created automatically after the first method. Save them: they are shown once. Each works once; you are warned when two or fewer are left, and “Create new codes” replaces the whole set.', 'modify-login'),
                        __('<strong>Passkeys</strong>: click “Add a passkey” and follow the browser’s prompt.', 'modify-login'),
                    )),
                    __('Signing in', 'modify-login') => self::ul(array(
                        __('After the correct password, the “Confirm it’s you” step asks for the code (or the passkey). “Having trouble?” switches to another method, such as a backup code.', 'modify-login'),
                        __('Codes from an authenticator app work once; a code that was just used is refused. After 5 wrong codes the person has to sign in again, and wrong codes count towards brute-force lockouts.', 'modify-login'),
                        __('“Remember me” from the password step is kept. The free plugin has no “trust this device” option.', 'modify-login'),
                        __('Logins from the WooCommerce My Account form and other front-end forms continue to the same step.', 'modify-login'),
                        __('Apps that log in with the account password over XML-RPC or the REST API are refused for people with two-factor; they must use an application password.', 'modify-login'),
                    )),
                    __('Who uses it', 'modify-login') => self::p(__('The Two-factor screen lists, per role, how many people use two-factor (backup codes alone do not count), and the Users list gets a “2FA” column. The dashboard checklist shows how many administrators use it.', 'modify-login')),
                    __('Limitations', 'modify-login') => self::ul(array(
                        __('In the free plugin two-factor is opt-in; it cannot be required for a role.', 'modify-login'),
                        __('If another two-factor plugin is active (Two Factor, WP 2FA or Wordfence Login Security), Authlify steps aside and says so.', 'modify-login'),
                        __('Authenticator secrets are encrypted with keys derived from your wp-config.php security keys. Changing <code>AUTH_KEY</code> or <code>SECURE_AUTH_SALT</code> makes them unreadable, so people then need a backup code, a passkey or a reset.', 'modify-login'),
                    )),
                )) . self::p(sprintf(__('Related: %1$s, %2$s, %3$s.', 'modify-login'), self::doc('passkeys', __('Passkeys', 'modify-login')), self::doc('twofa-recovery', __('Two-factor recovery', 'modify-login')), self::doc('howto-require-2fa', __('Require two-factor for administrators', 'modify-login')))),
                array('2fa', 'mfa', 'two factor', 'totp', 'authenticator', 'google authenticator', 'backup codes', 'otp', 'second step')
            ),

            self::a('passkeys', $c, __('Passkeys and security keys', 'modify-login'), __('Sign in with a fingerprint, face, screen lock or hardware key, as a second step or instead of the password.', 'modify-login'),
                self::sections(array(
                    __('Requirements', 'modify-login') => self::ul(array(
                        __('PHP 8.0 or newer on the server, with the OpenSSL extension. Otherwise the option is greyed out on the Two-factor screen, with the reason shown.', 'modify-login'),
                        __('https (or localhost while developing), and a browser or device that supports passkeys.', 'modify-login'),
                    )),
                    __('Setting one up', 'modify-login') => self::p(__('Under <strong>Users → Profile → Two-factor login</strong>, click <strong>Add a passkey</strong>, confirm with the device, and give it a name such as “MacBook Touch ID”. Passkeys can be renamed or removed there.', 'modify-login')),
                    __('Using it', 'modify-login') => self::ul(array(
                        __('<strong>As the second step</strong>: after the password, choose “Use my passkey”.', 'modify-login'),
                        __('<strong>Instead of the password</strong>: with <strong>Passkey sign-in</strong> on (the default), the login form shows “Sign in with a passkey” once anyone on the site has added one, in browsers that support passkeys. It counts as both factors and needs the device’s PIN or biometric.', 'modify-login'),
                        __('Passkey sign-in respects lockouts; failures are logged but do not count as wrong passwords.', 'modify-login'),
                    )),
                    __('Limitations', 'modify-login') => self::ul(array(
                        __('A passkey belongs to the site’s domain. After moving the site to another domain, everyone must add their passkeys again.', 'modify-login'),
                        __('Passkeys are stored as public keys only; the private key never leaves the person’s device.', 'modify-login'),
                    )),
                )),
                array('passkey', 'webauthn', 'fido2', 'security key', 'yubikey', 'touch id', 'face id', 'windows hello', 'passwordless')
            ),

            self::a('twofa-recovery', $c, __('Two-factor recovery', 'modify-login'), __('What to do when someone loses their phone and backup codes.', 'modify-login'),
                self::sections(array(
                    __('Recovery email (self-service)', 'modify-login') => self::ul(array(
                        __('On the two-factor step, “Can’t use your methods? Email me a recovery link” sends a link to the account’s email address. It is also available from a separate “Account recovery” form, which asks for the username or email and the password.', 'modify-login'),
                        __('The link works once, for 15 minutes, and replaces any earlier link. At most 3 links per account per hour (and 5 requests per address per hour from the separate form).', 'modify-login'),
                        __('Opening it shows a confirmation page first, so email scanners cannot use it. It signs the person in once and takes them to their profile to set up a new method. Their existing methods are kept, and the site admin address is notified.', 'modify-login'),
                        __('It is only offered after a correct password and when the account has a valid email address.', 'modify-login'),
                    )),
                    __('Reset by an administrator', 'modify-login') => self::p(sprintf(__('%s → <strong>Reset for a user</strong>: find the person by username or email and click <strong>Reset</strong>. It removes their authenticator app, backup codes and passkeys (you never see their secrets), so they can sign in with their password and set up again. Administrators can also use “Reset two-factor login” on the person’s profile.', 'modify-login'), $twofa)),
                    __('WP-CLI', 'modify-login') => self::code('wp authlify reset_2fa <user-id|login|email>'),
                    __('Emergency switch', 'modify-login') => self::p(__('This turns two-factor off for everyone without deleting anything. Remove it as soon as you are back in.', 'modify-login')) . self::code("define( 'AUTHLIFY_DISABLE_2FA', true );"),
                )),
                array('lost phone', 'recovery', 'reset 2fa', 'backup codes', 'AUTHLIFY_DISABLE_2FA', 'recovery email')
            ),

            self::a('designer', $c, __('Login page designer', 'modify-login'), __('Style every login screen to match your site, with templates, “Match my site” and a live preview.', 'modify-login'),
                self::sections(array(
                    __('Where to find it', 'modify-login') => self::p(self::go('modify-login-builder', __('Authlify → Designer', 'modify-login'))),
                    __('Templates and “Match my site”', 'modify-login') => self::ul(array(
                        __('Twelve templates: Default, Minimal light, Minimal dark, Glass over photo, Split image left, Split image right, Corporate blue, Soft gradient, Midnight, Warm sand, High contrast (WCAG AAA) and Sidebar.', 'modify-login'),
                        __('<strong>Match my site</strong> builds a design from your theme’s colors, font and logo (from theme.json and the Site Editor, the Customizer, and popular themes such as Astra, GeneratePress, Kadence and Blocksy). It reads your own site only, and nothing is saved until you click Save.', 'modify-login'),
                    )),
                    __('Sections', 'modify-login') => self::p(__('Colors, Layout (centered card, split image, sidebar, full bleed or the classic WordPress placement), Background, Logo, Form, Fields, Button, Links & text (font, text size, a message above the form, footer text), Messages and Custom CSS. Fonts are system fonts, bundled fonts (Inter, Nunito, Space Grotesk, Lora) or your theme’s font; nothing is loaded from a font CDN. A warning appears when text and background colors have too little contrast.', 'modify-login')),
                    __('Every screen', 'modify-login') => self::p(__('The preview switches between Log in, Lost password, Register, Reset password, the two-factor step, the lockout message, Confirm admin email, the log-out confirmation and the “session expired” pop-up, on desktop, tablet and phone sizes. One design styles them all.', 'modify-login')),
                    __('Saving and undo', 'modify-login') => self::ul(array(
                        __('<strong>Use this design</strong> switches your design on or off; when off, the standard WordPress login look is used. <strong>Save</strong> (or Ctrl/Cmd+S) publishes.', 'modify-login'),
                        __('Undo and Redo (Ctrl/Cmd+Z) keep 100 steps. Unsaved work is kept as a draft for a day and offered back when you return.', 'modify-login'),
                        __('<strong>More actions</strong>: revert to saved, reset to the Default template, start from plain WordPress, export the design as JSON, or import one.', 'modify-login'),
                    )),
                    __('What to expect', 'modify-login') => self::ul(array(
                        __('Nothing changes on a new site until you switch a design on and save.', 'modify-login'),
                        __('The styles are compiled into a small CSS file in <code>wp-content/uploads/authlify/</code>, or printed inline if that folder is not writable.', 'modify-login'),
                        __('Custom CSS is limited to 20,000 characters, and anything that could run scripts or load remote styles is removed.', 'modify-login'),
                        __('Sites upgraded from Modify Login 2.x keep their old look. Designs from LoginPress and Colorlib Login Customizer can be imported under Authlify → Settings; imported designs go live immediately.', 'modify-login'),
                    )),
                )),
                array('designer', 'customizer', 'login page', 'branding', 'logo', 'template', 'colors', 'css', 'match my site', 'background')
            ),
        );
    }

    /**
     * Redirects, activity log, settings tools, WP-CLI.
     *
     * @return array
     */
    private static function feature_site()
    {
        $c = 'features';

        return array(
            self::a('redirects', $c, __('Redirects', 'modify-login'), __('Choose where people land after they log in or out, for everyone or by role.', 'modify-login'),
                self::sections(array(
                    __('Where to find it', 'modify-login') => self::p(sprintf(__('%s (a tab of the Login URL screen).', 'modify-login'), self::go('authlify-redirects', __('Authlify → Login URL → Redirects', 'modify-login')))),
                    __('How to configure it', 'modify-login') => self::ul(array(
                        __('<strong>Everyone</strong>: <em>After login</em> and <em>After logout</em>. Leave empty for the WordPress default (the dashboard, or the page the person came from).', 'modify-login'),
                        __('<strong>By role</strong>: open a role to give it its own addresses. Empty fields use the addresses for everyone.', 'modify-login'),
                        __('Use <code>{username}</code> or <code>{user_id}</code> in an address, for example <code>/members/{username}/</code>. <code>{username}</code> is the person’s URL-friendly name (their “nicename”), not necessarily the name they log in with.', 'modify-login'),
                    )),
                    __('Which address wins', 'modify-login') => self::ul(array(
                        __('After login: a specific destination in the login link (<code>redirect_to</code>) wins, unless it is just the dashboard. Then the first of the person’s roles that has a rule, then the address for everyone.', 'modify-login'),
                        __('After logout: a specific destination in the logout link always wins, then the role rule, then the address for everyone.', 'modify-login'),
                        __('People who cannot use the dashboard are sent to the homepage instead of a wp-admin address.', 'modify-login'),
                    )),
                    __('Limitations', 'modify-login') => self::p(__('Only addresses on this site, or on hosts your site allows for redirects, are used. Anything else falls back to the WordPress default.', 'modify-login')),
                )),
                array('redirect', 'after login', 'after logout', 'role', 'landing page', 'placeholder')
            ),

            self::a('activity-log', $c, __('Activity log', 'modify-login'), __('Every login, failed attempt, lockout and security change, stored only in your own database.', 'modify-login'),
                self::sections(array(
                    __('Where to find it', 'modify-login') => self::p(sprintf(__('%s, with a <strong>Log</strong> tab and a <strong>Settings</strong> tab. The dashboard shows the last eight entries and seven-day totals.', 'modify-login'), self::go('modify-login-logs', __('Authlify → Activity', 'modify-login')))),
                    __('What is recorded', 'modify-login') => self::p(__('Logins, failed logins, lockouts and unlocks, blocked addresses, CAPTCHA failures, logouts, password resets, login address changes, log clearing, two-factor and passkey events, recovery emails, and refused breached passwords. Each entry keeps the time, event, user, username, IP address, browser (user agent), country when known, and details such as the channel (login form, XML-RPC, REST API or WooCommerce) and the reason for a failure.', 'modify-login')),
                    __('Using the log', 'modify-login') => self::ul(array(
                        __('Filter by event, by date range, or search for a username or IP address. Clicking an IP address searches for it (a partial match, so 1.2.3.4 also finds 1.2.3.45).', 'modify-login'),
                        __('<strong>Export CSV</strong> downloads the filtered entries (up to 100,000 rows). Cells that could be read as spreadsheet formulas are neutralised.', 'modify-login'),
                        __('50 entries per page, with Newer and Older buttons.', 'modify-login'),
                        __('<strong>Block</strong> next to an address adds it to the block list after you confirm (see Allow and block lists).', 'modify-login'),
                    )),
                    __('Settings', 'modify-login') => self::ul(array(
                        __('<strong>Activity log</strong> (on by default). Lockouts still work when it is off. Login address changes are always recorded.', 'modify-login'),
                        __('<strong>Keep entries for</strong> 90 days by default; older entries are deleted daily. 0 keeps them forever.', 'modify-login'),
                        __('<strong>Anonymize IPs</strong> (off by default): stores IPv4 addresses without their last part (203.0.113.0) and keeps only the first half of IPv6 addresses. Lockouts still use full addresses.', 'modify-login'),
                        __('<strong>Country</strong>: “Do not record”, or “Use the country Cloudflare provides”. In the free plugin a country is only recorded when the Visitor IP setting is “Through Cloudflare” (Cloudflare’s country header). It is never looked up through a third-party service.', 'modify-login'),
                        __('<strong>Lockout email</strong> (off by default): emails the site admin address when a lockout was triggered with an administrator’s username. At most one email per hour.', 'modify-login'),
                        __('<strong>New sign-in email</strong> (off by default): emails a user when their account signs in from a device (browser and system) or IP address it has not used before. Choose the roles under <strong>Who gets it</strong> (Administrator when first turned on). The first sign-in after it is turned on is only remembered. At most one email per account every 15 minutes. It runs after two-factor login, and links to the password reset. When Authlify Pro’s new-device alerts cover a user, Pro sends its own alert instead, so nobody gets two.', 'modify-login'),
                        __('<strong>Delete all entries</strong> empties the log at once. It cannot be undone.', 'modify-login'),
                    )),
                    __('Limitations', 'modify-login') => self::p(__('On multisite the log is shared by the network, so each site’s Activity screen shows entries from all sites, and clearing it clears every site.', 'modify-login')),
                )) . self::p(sprintf(__('Privacy details are in %s.', 'modify-login'), self::doc('privacy-retention', __('Retention, anonymization and personal data requests', 'modify-login')))),
                array('log', 'audit', 'history', 'failed logins', 'csv', 'export', 'retention', 'anonymize', 'country', 'new sign-in', 'login notification', 'new device', 'block')
            ),

            self::a('import-export', $c, __('Import, export and switching plugins', 'modify-login'), __('Move your setup between sites, copy settings from other login plugins, and decide what happens to your data on uninstall.', 'modify-login'),
                self::sections(array(
                    __('Where to find it', 'modify-login') => self::p(self::go('authlify-tools', __('Authlify → Settings', 'modify-login'))),
                    __('Switch from another plugin', 'modify-login') => self::p(__('Authlify lists the plugins whose settings it finds in your database, even if that plugin is no longer active. Click <strong>Import</strong> to copy them; nothing in the other plugin is changed.', 'modify-login')) . self::ul(array(
                        __('<strong>WPS Hide Login</strong>: the login address (applied immediately) and its redirect page.', 'modify-login'),
                        __('<strong>Limit Login Attempts Reloaded</strong>: allowed attempts, lockout length and the allow and block lists. It also turns brute-force protection on.', 'modify-login'),
                        __('<strong>Admin and Site Enhancements</strong>: its custom login address (applied immediately).', 'modify-login'),
                        __('<strong>LoginPress</strong> and <strong>Colorlib Login Customizer</strong>: their login page design, into the Designer.', 'modify-login'),
                    )) . self::p(__('WPS Hide Login and Limit Login Attempts Reloaded are also listed while they run on their default settings (nothing saved yet): Limit Login Attempts Reloaded’s defaults (4 attempts, 20 minutes) are imported; WPS Hide Login’s default address, /login, is one WordPress answers itself, so Authlify asks you to choose your own under Login URL instead.', 'modify-login'))
                    . self::note(__('After importing a login address, deactivate the other plugin’s login feature at once: two plugins changing the login address can reveal it or lock you out.', 'modify-login'), 'warning'),
                    __('Two-factor secrets', 'modify-login') => self::p(__('When Two Factor or WP 2FA left authenticator-app data on this site, a <strong>Two-factor secrets</strong> panel appears. <strong>Preview</strong> counts who would be imported and changes nothing; <strong>Import</strong> copies the secrets, so people keep the same entry in their authenticator app. Then deactivate the other plugin: Authlify’s two-factor login only runs while no other two-factor plugin is active.', 'modify-login')) . self::ul(array(
                        __('<strong>Two Factor</strong>: users who have the authenticator app turned on there.', 'modify-login'),
                        __('<strong>WP 2FA</strong>: users whose method is the authenticator app. Its encrypted secrets are read with WP 2FA’s own key (the <code>WP2FA_ENCRYPT_KEY</code> constant in wp-config.php, or its stored key), so import before you remove WP 2FA’s data or that constant.', 'modify-login'),
                        __('Skipped and counted: users who already have an authenticator app in Authlify, apps saved but not turned on, and secrets that cannot be read.', 'modify-login'),
                        __('Backup codes and email codes are not copied. <strong>Wordfence Login Security</strong> secrets cannot be copied (the format is not documented); its users set up their app again.', 'modify-login'),
                        __('Each imported user and each import run is recorded in the activity log.', 'modify-login'),
                    )),
                    __('Export', 'modify-login') => self::p(__('<strong>Download</strong> saves <code>authlify-settings-YYYY-MM-DD.json</code> with your settings and login page design. The CAPTCHA secret key and any unconfirmed login address change are left out. The file does contain the login address and the CAPTCHA site key, so store it privately.', 'modify-login')),
                    __('Import', 'modify-login') => self::ul(array(
                        __('Choose a file exported by Authlify and click <strong>Import settings</strong>. Other files are refused.', 'modify-login'),
                        __('Only settings this site knows are imported. The CAPTCHA secret key, the uninstall choice and the setup status are never imported.', 'modify-login'),
                        __('The login address is only imported when you tick <strong>Also import the login URL</strong>. It then goes through the usual confirmation step.', 'modify-login'),
                    )),
                    __('Data on uninstall', 'modify-login') => self::p(__('<strong>On uninstall</strong> (off by default): when on, deleting the plugin from the Plugins screen removes all Authlify settings, logs, lockout counters, two-factor data, passkeys and the compiled login page styles. Deactivating never deletes anything.', 'modify-login')),
                )),
                array('export', 'import', 'json', 'migrate', 'wps hide login', 'limit login attempts reloaded', 'loginpress', 'colorlib', 'admin site enhancements', 'uninstall', 'delete data', 'backup', 'two factor', 'wp 2fa', 'wordfence', 'totp', 'authenticator', '2fa import')
            ),

            self::a('wp-cli', $c, __('WP-CLI commands', 'modify-login'), __('Recover the login address, clear lockouts, reset two-factor and run Leak Check from the command line.', 'modify-login'),
                self::sections(array(
                    __('Login address', 'modify-login') => self::code("wp authlify url get\nwp authlify url set my-private-door\nwp authlify url reset") . self::ul(array(
                        __('<code>get</code> prints the login address.', 'modify-login'),
                        __('<code>set</code> applies a new address immediately (no confirmation step) and emails it to the site admin address. It refuses while <code>AUTHLIFY_SLUG</code> or <code>AUTHLIFY_DISABLE_HIDE</code> is defined.', 'modify-login'),
                        __('<code>reset</code> turns the custom address off, so wp-login.php works again.', 'modify-login'),
                    )),
                    __('Lockouts', 'modify-login') => self::code("wp authlify lockouts [--format=table|json|csv]\nwp authlify unlock 203.0.113.7\nwp authlify unlock 198.51.100.0/24\nwp authlify unlock --all") . self::p(__('Unlocking an address also clears its network lockout.', 'modify-login')),
                    __('Two-factor', 'modify-login') => self::code('wp authlify reset_2fa <user>') . self::p(__('Removes a person’s two-factor methods so they can log in with their password and set it up again. Accepts a user ID, login name or email. Note the underscore in <code>reset_2fa</code>.', 'modify-login')),
                    __('Leak Check', 'modify-login') => self::code('wp authlify leak-check [--format=table|json|csv]') . self::p(__('Runs Leak Check now and prints each result. It exits with an error when a leak is found, so you can use it in deployment scripts.', 'modify-login')),
                )),
                array('cli', 'command line', 'terminal', 'ssh', 'wp authlify', 'unlock', 'reset')
            ),
        );
    }

    /* ----------------------------------------------------------------------
     * Pro features (overviews; Authlify Pro replaces them with full articles)
     * ------------------------------------------------------------------- */

    /**
     * A Pro overview article.
     *
     * @param string $id       ID.
     * @param string $title    Title.
     * @param string $summary  Summary.
     * @param array  $points   What it does (HTML items).
     * @param string $where    Where it lives once Pro is active.
     * @param array  $keywords Search words.
     * @return array
     */
    private static function pro($id, $title, $summary, array $points, $where, array $keywords)
    {
        return self::a($id, 'pro', $title, $summary,
            '<h3>' . esc_html__('What it gives you', 'modify-login') . '</h3>' . self::ul($points)
            . '<h3>' . esc_html__('Where it lives', 'modify-login') . '</h3>' . self::p(esc_html($where)),
            $keywords,
            true
        );
    }

    /**
     * Pro overview articles.
     *
     * @return array
     */
    private static function pro_articles()
    {
        return array(
            self::pro('pro-2fa-policies', __('Required two-factor for roles', 'modify-login'), __('Make two-factor login mandatory for the roles that matter, with a fair grace period to set it up.', 'modify-login'), array(
                __('Choose the roles that must use two-factor, such as administrators, editors and shop managers.', 'modify-login'),
                __('A grace period of a number of sign-ins or days, with a friendly “Protect your account” prompt; after it, a guided setup wizard runs before the person can continue.', 'modify-login'),
                __('Passkey-only roles that can no longer sign in with a password once they have a passkey.', 'modify-login'),
                __('A coverage report per role (compliant, in grace, overdue) with a CSV download.', 'modify-login'),
            ), __('Authlify → Two-factor → Rules & report.', 'modify-login'), array('enforce 2fa', 'require', 'mandatory', 'grace period', 'policy', 'report', 'passkey only')),

            self::pro('pro-email-codes', __('Email codes', 'modify-login'), __('A one-time code by email as a two-factor method, for people who do not use an authenticator app.', 'modify-login'), array(
                __('6-digit codes that expire after 10 minutes and allow 5 attempts, with sensible resend limits.', 'modify-login'),
                __('Set up by each person from their profile, and offered in the setup wizard.', 'modify-login'),
                __('Sent in your branded email layout.', 'modify-login'),
            ), __('Authlify → Two-factor → Rules & report → Methods and devices.', 'modify-login'), array('email code', 'email otp', 'email 2fa', 'one-time code')),

            self::pro('pro-trusted-devices', __('Trusted devices', 'modify-login'), __('Let people skip the second step on a browser they have already verified.', 'modify-login'), array(
                __('A “Remember this device for N days” box on the two-factor step (1 to 365 days, 30 by default).', 'modify-login'),
                __('Each person sees and forgets their remembered devices; devices are forgotten on a password change or two-factor reset.', 'modify-login'),
                __('An administrator can forget every remembered device at once.', 'modify-login'),
            ), __('Authlify → Two-factor → Rules & report → Methods and devices.', 'modify-login'), array('remember device', 'trust this device', 'skip 2fa', 'remember me')),

            self::pro('pro-sudo', __('Sudo mode', 'modify-login'), __('Ask people to confirm it is really them before sensitive changes, even inside a signed-in session.', 'modify-login'), array(
                __('Installing or activating plugins and themes, creating users, promoting someone to administrator, creating application passwords and changing Authlify settings ask for a fresh confirmation.', 'modify-login'),
                __('Confirmation uses the person’s two-factor method (or password) and lasts 15 minutes by default.', 'modify-login'),
                __('Stops a stolen session cookie from taking over the site.', 'modify-login'),
            ), __('Authlify → Two-factor → Rules & report → Sudo mode.', 'modify-login'), array('sudo', 're-authentication', 'step-up', 'confirm identity', 'session hijack')),

            self::pro('pro-magic-links', __('Login links and email sign-in codes', 'modify-login'), __('Passwordless sign-in by email for the roles you choose, such as customers and subscribers.', 'modify-login'), array(
                __('“Email me a login link” (single use, 15 minutes) and/or a 6-digit sign-in code (10 minutes).', 'modify-login'),
                __('Per-role eligibility; administrators only if you explicitly allow them.', 'modify-login'),
                __('Optionally bind the link to the browser that asked for it.', 'modify-login'),
                __('Rate limits and identical answers whether or not an account exists.', 'modify-login'),
            ), __('Authlify → Sign-in methods → Passwordless.', 'modify-login'), array('magic link', 'passwordless', 'email login', 'otp', 'login link')),

            self::pro('pro-temporary-access', __('Temporary access links', 'modify-login'), __('Give a developer, support agent or client time-limited access without sharing a password.', 'modify-login'), array(
                __('Create a link with a role and an expiry (1 hour to 30 days), optional use limit and IP restriction.', 'modify-login'),
                __('The temporary account cannot use a password, reset it or create application passwords.', 'modify-login'),
                __('When it ends, the account is deleted (content goes to you) or kept without a role. Revoke at any time.', 'modify-login'),
            ), __('Authlify → Sign-in methods → Passwordless → Temporary access.', 'modify-login'), array('temporary login', 'temp access', 'support access', 'guest admin', 'expiring link')),

            self::pro('pro-social-login', __('Social login and single sign-on', 'modify-login'), __('“Continue with Google, Microsoft, Apple or GitHub”, or any OpenID Connect provider such as Okta, Entra ID or Auth0.', 'modify-login'), array(
                __('Guided setup per provider, with the exact callback URL to copy and a “Test connection” button.', 'modify-login'),
                __('Secure by default: verified email required, optional auto-linking to existing accounts, per-provider allowed email domains, and privileged accounts blocked unless you allow them.', 'modify-login'),
                __('Optional sign-up with a safe default role; buttons on the login, registration and WooCommerce forms.', 'modify-login'),
                __('Two-factor and your policies still apply after a social sign-in.', 'modify-login'),
            ), __('Authlify → Sign-in methods → Social & SSO.', 'modify-login'), array('social login', 'google login', 'microsoft', 'azure', 'entra', 'apple', 'github', 'oidc', 'openid connect', 'sso', 'okta')),

            self::pro('pro-alerts', __('Login alerts', 'modify-login'), __('Tell people and admins about unusual logins and risky changes as they happen.', 'modify-login'), array(
                __('New-device and new-country alerts to the account owner, with a “This wasn’t me” button that ends every session and forces a password reset.', 'modify-login'),
                __('Admin alerts for administrator lockouts, new or promoted administrators, application passwords, two-factor being turned off, login address changes and failed-login spikes.', 'modify-login'),
                __('A weekly security digest and scheduled CSV exports of the log.', 'modify-login'),
            ), __('Authlify → Activity → Alerts.', 'modify-login'), array('alerts', 'notifications', 'new device', 'new country', 'this wasnt me', 'digest', 'email alerts')),

            self::pro('pro-webhooks', __('Slack, Discord, Teams, Telegram and webhooks', 'modify-login'), __('Send security events to the chat or system your team already watches.', 'modify-login'), array(
                __('Up to 10 channels, each with its own list of events.', 'modify-login'),
                __('Generic JSON webhooks are signed with HMAC-SHA256 so your receiver can verify them.', 'modify-login'),
                __('Throttling, one retry and a “Send a test message” button.', 'modify-login'),
            ), __('Authlify → Activity → Alerts → Chat and webhooks.', 'modify-login'), array('slack', 'discord', 'teams', 'telegram', 'webhook', 'hmac', 'signature', 'siem')),

            self::pro('pro-geo-db', __('Local country database', 'modify-login'), __('Accurate countries for every login without sending visitor addresses to a lookup service.', 'modify-login'), array(
                __('Downloads the free DB-IP Lite country database once a month and looks addresses up on your own server.', 'modify-login'),
                __('Powers the Country column, new-country alerts, country rules and the attack insights.', 'modify-login'),
            ), __('Activity → Settings → Country → Local country database.', 'modify-login'), array('geoip', 'country', 'db-ip', 'geolocation', 'maxmind')),

            self::pro('pro-sessions', __('Sessions', 'modify-login'), __('Control how long people stay signed in and on how many devices.', 'modify-login'), array(
                __('Maximum session length and number of simultaneous devices, per role.', 'modify-login'),
                __('Idle logout after a period without activity, with a “Stay logged in” warning.', 'modify-login'),
                __('An “Active sessions” list on each profile, with “Log out everywhere else”.', 'modify-login'),
            ), __('Authlify → Security → Sessions.', 'modify-login'), array('sessions', 'idle timeout', 'concurrent logins', 'logout everywhere', 'session length')),

            self::pro('pro-password-policy', __('Password policy', 'modify-login'), __('Minimum length, character mix, history and expiry, per role, plus a breach check at login.', 'modify-login'), array(
                __('Minimum length (12 by default), a character mix with a passphrase allowance, no reuse of recent passwords, and optional expiry.', 'modify-login'),
                __('Checks existing passwords against known breaches at login and asks for a new one by email.', 'modify-login'),
                __('Forced password resets, for example after “This wasn’t me”.', 'modify-login'),
            ), __('Authlify → Security → Password policy.', 'modify-login'), array('password policy', 'password strength', 'expiry', 'history', 'complexity', 'breached at login')),

            self::pro('pro-access-rules', __('Access rules', 'modify-login'), __('Allow or refuse logins by country and by time of day.', 'modify-login'), array(
                __('Only allow, or refuse, logins from chosen countries.', 'modify-login'),
                __('Login hours per role, in your site’s time zone.', 'modify-login'),
                __('Guards that stop you saving a rule that would lock yourself out, and an emergency switch.', 'modify-login'),
            ), __('Authlify → Security → Access rules.', 'modify-login'), array('country block', 'geo block', 'login hours', 'time restriction', 'access control')),

            self::pro('pro-honeypot-url', __('Honeypot login URL', 'modify-login'), __('Ban addresses that keep probing wp-login.php or guessed login addresses.', 'modify-login'), array(
                __('After a few hits on the hidden wp-login.php, /wp-admin/ or guessed paths such as /login, the address is banned from logging in for a while.', 'modify-login'),
                __('People who logged in recently and allow-listed addresses are never banned.', 'modify-login'),
            ), __('Authlify → Security → Access rules → Honeypot login URL.', 'modify-login'), array('honeypot url', 'ban', 'probe', 'scanner', 'trap')),

            self::pro('pro-templates', __('Premium templates and effects', 'modify-login'), __('22 more login page templates and animated backgrounds.', 'modify-login'), array(
                __('Animated, video, seasonal and industry templates (SaaS, agency, school, clinic, store and more).', 'modify-login'),
                __('Effects: flowing gradients, aurora glow, floating orbs, falling snow, petals or leaves, festive lights and video backgrounds. They respect “reduce motion”.', 'modify-login'),
            ), __('Authlify → Designer (template gallery and Effect section).', 'modify-login'), array('templates', 'animated', 'video background', 'seasonal', 'effects', 'premium design')),

            self::pro('pro-emails', __('Branded emails', 'modify-login'), __('Every Pro email, and optionally WordPress’s own account emails, in a clean layout that matches your login page.', 'modify-login'), array(
                __('HTML emails with a plain-text version, using your login design’s colors and logo.', 'modify-login'),
                __('Optional wrapping of WordPress’s password reset, new user and email change emails.', 'modify-login'),
                __('Preview and test email.', 'modify-login'),
            ), __('Authlify → Designer → Emails & extras.', 'modify-login'), array('email template', 'branded email', 'html email', 'password reset email')),

            self::pro('pro-blocks', __('Login blocks, popup and login page', 'modify-login'), __('Put login, registration and lost-password forms anywhere, or use a page of your own as the login page.', 'modify-login'), array(
                __('Blocks: Login form, Registration form, Lost password form, Account menu and Login popup, plus a popup shortcode.', 'modify-login'),
                __('Forms post to your real login address, so lockouts, CAPTCHA and two-factor still apply.', 'modify-login'),
                __('Use a published page built with the block editor as your login page.', 'modify-login'),
            ), __('Block editor (Authlify category) and Authlify → Designer → Emails & extras.', 'modify-login'), array('block', 'gutenberg', 'popup', 'modal', 'shortcode', 'login page', 'site editor', 'front end login')),

            self::pro('pro-woocommerce', __('WooCommerce extras', 'modify-login'), __('A branded My Account login and a Security tab for customers.', 'modify-login'), array(
                __('The two-factor step shown inside My Account instead of the WordPress login page.', 'modify-login'),
                __('A “Security” tab in My Account (or a shortcode) for two-factor, email codes, remembered devices and connected accounts.', 'modify-login'),
                __('Your login design applied to the My Account login form.', 'modify-login'),
            ), __('Authlify → Two-factor → Rules & report, and Authlify → Designer → Emails & extras.', 'modify-login'), array('woocommerce', 'my account', 'customers', 'store', 'shop')),

            self::pro('pro-agency', __('Agency tools', 'modify-login'), __('White-label, client handoff, network-wide settings with locks, and design sync between sites.', 'modify-login'), array(
                __('White-label: your own plugin name, menu label and author, and hide the plugin from everyone but your team.', 'modify-login'),
                __('Client handoff: clients keep the dashboard and activity log, only your team changes settings.', 'modify-login'),
                __('Multisite: network settings with per-site overrides that can only be stricter, and locked settings.', 'modify-login'),
                __('Copy a login design between sites with a sync key, or as a file.', 'modify-login'),
            ), __('Authlify → Settings → Agency.', 'modify-login'), array('agency', 'white label', 'rebrand', 'client', 'handoff', 'network', 'multisite', 'lock', 'design sync')),

            self::pro('pro-rest-api', __('REST API', 'modify-login'), __('A documented API to read and change settings, read the activity log, manage lockouts and sync designs.', 'modify-login'), array(
                __('Routes under /wp-json/authlify-pro/v1/ for settings, activity (JSON or CSV), lockouts and the login design.', 'modify-login'),
                __('Authenticated with WordPress application passwords; secrets are never returned; changes go through the same validation and lock-out guards as the admin screens.', 'modify-login'),
                __('WP-CLI commands for licenses, settings export and import, and applying settings to many sites.', 'modify-login'),
            ), __('/wp-json/authlify-pro/v1/ and wp authlify-pro.', 'modify-login'), array('rest api', 'api', 'automation', 'integration', 'cli', 'devops')),

            self::pro('pro-license', __('License and updates', 'modify-login'), __('How the Pro license works, and why a lapsed license never switches protection off.', 'modify-login'), array(
                __('The license gives you updates and support.', 'modify-login'),
                __('Every Pro feature keeps working if the license lapses; nothing security-related is ever turned off.', 'modify-login'),
            ), __('Authlify → Settings → License.', 'modify-login'), array('license', 'licence', 'updates', 'renew', 'activation')),
        );
    }

    /* ----------------------------------------------------------------------
     * Configuration reference
     * ------------------------------------------------------------------- */

    /**
     * Settings reference articles. Defaults are read from Settings::schema().
     *
     * @return array
     */
    private static function config_articles()
    {
        $c = 'config';
        $intro = __('Defaults below are read from the plugin itself, so they are always current. On sites upgraded from Modify Login 1.x or 2.x, brute-force protection, two-factor login and the weekly Leak Check start off instead.', 'modify-login');

        return array(
            self::a('config-login', $c, __('Login URL and redirects settings', 'modify-login'), __('Every Login URL and Redirects setting, with its default and a recommended value.', 'modify-login'),
                self::p($intro) . self::table(array(
                    array(__('New login address', 'modify-login'), 'login_slug', self::def('login_slug'), __('A private, non-dictionary address', 'modify-login'), __('Empty means wp-login.php. A new value applies only after you confirm it.', 'modify-login')),
                    array(__('Hide default URLs', 'modify-login'), 'block_wp_login', self::def('block_wp_login'), __('On', 'modify-login'), __('Hides wp-login.php and /wp-admin/ from visitors. Needs a login address.', 'modify-login')),
                    array(__('Show visitors', 'modify-login'), 'blocked_response', self::def('blocked_response'), '404', __('404 shows your theme’s “page not found”. 403 shows “access denied”. redirect sends visitors to the address below.', 'modify-login')),
                    array(__('Redirect to', 'modify-login'), 'blocked_redirect_url', self::def('blocked_redirect_url'), __('An address on this site', 'modify-login'), __('Only used with the redirect response. Empty means the homepage.', 'modify-login')),
                    array(__('After login', 'modify-login'), 'login_redirect_url', self::def('login_redirect_url'), __('Empty', 'modify-login'), __('Where everyone lands after logging in. Supports {username} and {user_id}.', 'modify-login')),
                    array(__('After logout', 'modify-login'), 'logout_redirect_url', self::def('logout_redirect_url'), __('Empty', 'modify-login'), __('Where everyone lands after logging out.', 'modify-login')),
                    array(__('By role', 'modify-login'), 'role_redirects', self::def('role_redirects'), __('Only where needed', 'modify-login'), __('Per-role login and logout addresses; the first matching role wins.', 'modify-login')),
                )),
                array('settings', 'reference', 'defaults', 'login_slug', 'block_wp_login', 'blocked_response')
            ),

            self::a('config-security', $c, __('Security settings', 'modify-login'), __('Brute force, visitor IP, allow and block lists, and hardening.', 'modify-login'),
                self::p($intro) . self::table(array(
                    array(__('Brute-force protection', 'modify-login'), 'limit_enabled', self::def('limit_enabled'), __('On', 'modify-login'), __('Off disables lockouts and the allow and block lists.', 'modify-login')),
                    array(__('Failed attempts allowed', 'modify-login'), 'limit_attempts', self::def('limit_attempts'), '5', __('Per IP address, within the time window. Allowed range 1–100.', 'modify-login')),
                    array(__('Time window', 'modify-login'), 'limit_window', self::def('limit_window') . ' min', '15 min', __('How long failures are remembered. 1–1440 minutes.', 'modify-login')),
                    array(__('Lockout length', 'modify-login'), 'lockout_minutes', self::def('lockout_minutes') . ' min', '15 min', __('The first lockout. 1–10080 minutes.', 'modify-login')),
                    array(__('Escalating lockouts', 'modify-login'), 'lockout_escalate', self::def('lockout_escalate'), __('On', 'modify-login'), __('Repeat lockouts last 4×, 16×, then 96× the lockout length.', 'modify-login')),
                    array(__('Network lockouts', 'modify-login'), 'limit_network', self::def('limit_network'), __('Off, unless attacks rotate addresses', 'modify-login'), __('Locks a /24 (IPv6 /48) after 3× the allowed failures across it. Can lock out shared offices.', 'modify-login')),
                    array(__('Targeted-account threshold', 'modify-login'), 'user_attempts', self::def('user_attempts'), '10', __('Failures on one username from many addresses before its login needs a CAPTCHA (CAPTCHA “after failures” mode only). 1–1000.', 'modify-login')),
                    array(__('Account pause', 'modify-login'), 'limit_user_lock', self::def('limit_user_lock'), __('On', 'modify-login'), __('At twice the targeted-account threshold, pauses the account for addresses it has never logged in from.', 'modify-login')),
                    array(__('Where visitors connect from', 'modify-login'), 'ip_source', self::def('ip_source'), __('Whatever “Detected setup” recommends', 'modify-login'), __('remote_addr, cloudflare or proxy. A wrong value can lock everyone out at once or let attackers fake addresses.', 'modify-login')),
                    array(__('Trusted proxies', 'modify-login'), 'trusted_proxies', self::def('trusted_proxies'), __('Your proxy addresses', 'modify-login'), __('Only used with “my own proxy”.', 'modify-login')),
                    array(__('Never lock out', 'modify-login'), 'ip_allowlist', self::def('ip_allowlist'), __('Your fixed IP', 'modify-login'), __('Never counted, locked or asked for a CAPTCHA.', 'modify-login')),
                    array(__('Always block', 'modify-login'), 'ip_denylist', self::def('ip_denylist'), __('Empty', 'modify-login'), __('Can never log in.', 'modify-login')),
                    array(__('XML-RPC', 'modify-login'), 'xmlrpc', self::def('xmlrpc'), 'no_multicall', __('on, no_multicall or off.', 'modify-login')),
                    array(__('Application passwords', 'modify-login'), 'app_passwords', self::def('app_passwords'), __('admins, or off if unused', 'modify-login'), __('on, admins or off.', 'modify-login')),
                    array(__('Username discovery', 'modify-login'), 'block_user_enumeration', self::def('block_user_enumeration'), __('On', 'modify-login'), __('Hides usernames from the REST API, author links, sitemaps and oEmbed.', 'modify-login')),
                    array(__('Login error messages', 'modify-login'), 'generic_errors', self::def('generic_errors'), __('On', 'modify-login'), __('One vague error for any wrong username or password.', 'modify-login')),
                    array(__('Force login', 'modify-login'), 'force_login', self::def('force_login'), __('Off, unless the site is private', 'modify-login'), __('Visitors must log in to see anything.', 'modify-login')),
                    array(__('Public pages', 'modify-login'), 'force_login_exclude', self::def('force_login_exclude'), __('As needed', 'modify-login'), __('Paths that stay public under Force login.', 'modify-login')),
                )),
                array('settings', 'reference', 'defaults', 'limit_attempts', 'lockout_minutes', 'ip_source', 'xmlrpc')
            ),

            self::a('config-captcha', $c, __('CAPTCHA settings', 'modify-login'), __('Provider, keys, forms, modes and safety options.', 'modify-login'),
                self::table(array(
                    array(__('Provider', 'modify-login'), 'captcha_provider', self::def('captcha_provider'), 'turnstile', __('none, turnstile, hcaptcha, recaptcha_v2, recaptcha_v3 or altcha.', 'modify-login')),
                    array(__('Site key', 'modify-login'), 'captcha_site_key', self::def('captcha_site_key'), __('From your provider', 'modify-login'), __('Public. Proven by completing the preview.', 'modify-login')),
                    array(__('Secret key', 'modify-login'), 'captcha_secret_key', self::def('captcha_secret_key'), __('From your provider', 'modify-login'), __('Checked with the provider when you save. Never exported.', 'modify-login')),
                    array(__('Minimum score', 'modify-login'), 'captcha_v3_threshold', self::def('captcha_v3_threshold'), '0.5', __('reCAPTCHA v3 only. Lower it if real people are refused.', 'modify-login')),
                    array(__('Forms', 'modify-login'), 'captcha_forms', self::def('captcha_forms'), __('Login, registration, lost password', 'modify-login'), __('Also comments and WooCommerce forms when chosen.', 'modify-login')),
                    array(__('When to show it', 'modify-login'), 'captcha_mode', self::def('captcha_mode'), 'after_failures', __('always, or only after failed logins (login forms).', 'modify-login')),
                    array(__('Failed logins before it appears', 'modify-login'), 'captcha_after', self::def('captcha_after'), '2', __('Only with “after failures”.', 'modify-login')),
                    array(__('Test mode', 'modify-login'), 'captcha_test_mode', self::def('captcha_test_mode'), __('On for the first days', 'modify-login'), __('Checks and logs but never blocks.', 'modify-login')),
                    array(__('If the provider is down', 'modify-login'), 'captcha_fail', self::def('captcha_fail'), 'open', __('open lets people through (lockouts still apply); closed blocks the form.', 'modify-login')),
                    array(__('Honeypot', 'modify-login'), 'honeypot', self::def('honeypot'), __('On', 'modify-login'), __('An invisible bot trap on the same forms.', 'modify-login')),
                )),
                array('settings', 'reference', 'defaults', 'captcha_provider', 'captcha_mode', 'captcha_fail')
            ),

            self::a('config-identity', $c, __('Two-factor and password settings', 'modify-login'), __('Two-factor methods, the passkey button and the breached-password check.', 'modify-login'),
                self::table(array(
                    array(__('Two-factor login', 'modify-login'), 'twofa_enabled', self::def('twofa_enabled'), __('On', 'modify-login'), __('Off hides the profile section and skips the second step. Existing setups are kept.', 'modify-login')),
                    array(__('Offered methods', 'modify-login'), 'twofa_methods', self::def('twofa_methods'), __('All three', 'modify-login'), __('totp (authenticator app), backup (backup codes), passkey. Turning one off stops new setups only.', 'modify-login')),
                    array(__('Passkey sign-in', 'modify-login'), 'passkey_login_button', self::def('passkey_login_button'), __('On', 'modify-login'), __('“Sign in with a passkey” on the login form, once someone has a passkey.', 'modify-login')),
                    array(__('Breached-password check', 'modify-login'), 'hibp_enabled', self::def('hibp_enabled'), __('On', 'modify-login'), __('Refuses new passwords found in known breaches. Contacts the Have I Been Pwned range API.', 'modify-login')),
                    array(__('Roles to check', 'modify-login'), 'hibp_roles', self::def('hibp_roles'), __('Administrators and editors, or everyone', 'modify-login'), __('Which roles the check applies to.', 'modify-login')),
                )),
                array('settings', 'reference', 'defaults', 'twofa_methods', 'hibp', 'passkey')
            ),

            self::a('config-activity', $c, __('Activity and data settings', 'modify-login'), __('What the log keeps, for how long, alerts, and data on uninstall.', 'modify-login'),
                self::table(array(
                    array(__('Activity log', 'modify-login'), 'log_enabled', self::def('log_enabled'), __('On', 'modify-login'), __('Records logins and failed attempts.', 'modify-login')),
                    array(__('Keep entries for', 'modify-login'), 'log_retention_days', self::def('log_retention_days') . ' ' . __('days', 'modify-login'), __('90 days', 'modify-login'), __('0 keeps entries forever.', 'modify-login')),
                    array(__('Anonymize IPs', 'modify-login'), 'log_anonymize_ip', self::def('log_anonymize_ip'), __('On if your privacy policy needs it', 'modify-login'), __('Stores shortened addresses in the log. Lockouts still use full addresses.', 'modify-login')),
                    array(__('Country', 'modify-login'), 'geo_source', self::def('geo_source'), 'headers', __('off, headers (Cloudflare’s country header) or dbip (Authlify Pro’s local database).', 'modify-login')),
                    array(__('Lockout email', 'modify-login'), 'alert_admin_lockout', self::def('alert_admin_lockout'), __('On', 'modify-login'), __('Emails the site admin when an administrator’s username triggers a lockout.', 'modify-login')),
                    array(__('On uninstall', 'modify-login'), 'delete_data', self::def('delete_data'), __('Off, unless you are removing Authlify for good', 'modify-login'), __('Deletes every Authlify table, setting and user meta when the plugin is deleted.', 'modify-login')),
                )),
                array('settings', 'reference', 'defaults', 'retention', 'log_retention_days', 'delete_data')
            ),
        );
    }

    /* ----------------------------------------------------------------------
     * How-to guides
     * ------------------------------------------------------------------- */

    /**
     * Task-oriented guides.
     *
     * @return array
     */
    private static function howto_articles()
    {
        $c = 'howto';

        return array(
            self::a('howto-hide-login', $c, __('How to hide your login page safely', 'modify-login'), __('Move the login page without ever locking yourself out.', 'modify-login'),
                self::ul(array(
                    __('Keep a second browser window logged in to wp-admin until you finish.', 'modify-login'),
                    sprintf(__('Open %s and type a new address that is not a word people would guess, such as the suggestion under the field. Click <strong>Save changes</strong>.', 'modify-login'), self::go('authlify-login-url', __('Authlify → Login URL', 'modify-login'))),
                    __('Click <strong>Open and confirm the new URL</strong>. The address is active once the Login URL screen says it is confirmed.', 'modify-login'),
                    __('In a private window, open the new address and log in. Then open <code>/wp-login.php</code> and <code>/wp-admin/</code>: both should show “page not found”.', 'modify-login'),
                    __('Bookmark the new address and share it with the other people who log in. The confirmation email is your backup copy.', 'modify-login'),
                    __('Wait a minute, then look at Leak Check on the dashboard. It should say the address was not found anywhere.', 'modify-login'),
                    __('If you use a page cache, check that it does not cache the new address (Tools → Site Health lists the steps for the cache it detects).', 'modify-login'),
                ), true) . self::note(sprintf(__('If you ever lose the address, add %s to wp-config.php or run <code>wp authlify url get</code>.', 'modify-login'), "<code>define( 'AUTHLIFY_DISABLE_HIDE', true );</code>")),
                array('hide login', 'change login url', 'rename wp-login', 'safe')
            ),

            self::a('howto-cloudflare', $c, __('Protect a site behind Cloudflare', 'modify-login'), __('Get real visitor addresses, the right country, and a login page Cloudflare never caches.', 'modify-login'),
                self::ul(array(
                    sprintf(__('Open %s. Under <strong>Visitor IP address</strong>, choose <strong>Through Cloudflare</strong> and save.', 'modify-login'), self::go('authlify-protection', __('Security → Brute force', 'modify-login'), array('tab' => 'limits'))),
                    __('Check that “Detected setup” shows <em>Matches your setting</em> and that “Your IP address as seen now” is your own public address.', 'modify-login'),
                    __('The Activity log now records the country Cloudflare reports (keep <strong>Activity → Settings → Country</strong> on “Use the country Cloudflare provides”).', 'modify-login'),
                    __('Authlify marks the login page as not cacheable. If you use Cloudflare APO or a “Cache Everything” rule, add a bypass rule for your login address as well; Site Health reminds you when it detects APO.', 'modify-login'),
                    __('For a CAPTCHA, Cloudflare Turnstile is a natural fit, but it does not require Cloudflare hosting.', 'modify-login'),
                ), true) . self::note(__('Only choose “Through Cloudflare” when traffic really passes through Cloudflare (orange cloud). Otherwise Authlify ignores the header and uses the connecting address, which is safe but means the setting has no effect.', 'modify-login')),
                array('cloudflare', 'cdn', 'proxy', 'real ip', 'apo', 'cache everything')
            ),

            self::a('howto-require-2fa', $c, __('Require two-factor for administrators', 'modify-login'), __('Get every administrator onto two-factor login, and what enforcement needs.', 'modify-login'),
                self::p(__('In the free plugin, two-factor login is opt-in: each person turns it on for their own account. You can make sure every administrator does:', 'modify-login'))
                . self::ul(array(
                    sprintf(__('Check %s: <strong>Two-factor login</strong> is on and the methods you want are offered.', 'modify-login'), self::go('authlify-two-factor', __('Authlify → Two-factor', 'modify-login'))),
                    __('Set it up for yourself with <strong>Set up for my account</strong>, and store the backup codes safely.', 'modify-login'),
                    __('Ask each administrator to do the same from <strong>Users → Profile</strong>. The Two-factor screen lists who uses it, and the dashboard checklist shows how many administrators are covered.', 'modify-login'),
                ), true)
                . self::p(sprintf(__('To make two-factor mandatory for chosen roles, with a grace period to set it up, see %s (Authlify Pro).', 'modify-login'), self::doc('pro-2fa-policies', __('Required two-factor for roles', 'modify-login')))),
                array('2fa', 'mfa', 'enforce', 'mandatory', 'administrators', 'policy')
            ),

            self::a('howto-from-wps-hide-login', $c, __('Move from WPS Hide Login', 'modify-login'), __('Keep your existing login address and switch plugins without a gap.', 'modify-login'),
                self::ul(array(
                    __('Install and activate Authlify while WPS Hide Login is still active.', 'modify-login'),
                    sprintf(__('Open %s. Under <strong>Switch from another plugin</strong>, click <strong>Import</strong> next to WPS Hide Login.', 'modify-login'), self::go('authlify-tools', __('Authlify → Settings', 'modify-login'))),
                    __('Authlify takes over the same login address straight away and emails it to you. Its redirect page setting is copied too.', 'modify-login'),
                    __('Deactivate WPS Hide Login immediately. Two plugins changing the login address can reveal it or lock you out.', 'modify-login'),
                    __('Log in at the same address in a private window to confirm, then check Leak Check on the dashboard.', 'modify-login'),
                ), true) . self::p(__('The same screen imports from Limit Login Attempts Reloaded (attempts, lockout length, allow and block lists), Admin and Site Enhancements (login address), LoginPress and Colorlib Login Customizer (login page design).', 'modify-login')),
                array('wps hide login', 'migrate', 'switch', 'import', 'replace plugin')
            ),

            self::a('howto-locked-out', $c, __('Get back in when locked out', 'modify-login'), __('Find your situation below; each has a way back in that needs no support ticket.', 'modify-login'),
                '<div class="authlify-docs__table"><table><thead><tr><th scope="col">' . esc_html__('Situation', 'modify-login') . '</th><th scope="col">' . esc_html__('Way back in', 'modify-login') . '</th></tr></thead><tbody>'
                . '<tr><td>' . esc_html__('Forgot the login address', 'modify-login') . '</td><td>' . wp_kses(__('Search your email for “Your login address”, run <code>wp authlify url get</code>, or add <code>define( \'AUTHLIFY_DISABLE_HIDE\', true );</code> to wp-config.php.', 'modify-login'), array('code' => array())) . '</td></tr>'
                . '<tr><td>' . esc_html__('“Too many failed login attempts”', 'modify-login') . '</td><td>' . wp_kses(__('Wait for the time shown, use “Email me an unlock link”, ask another administrator, or run <code>wp authlify unlock --all</code>.', 'modify-login'), array('code' => array())) . '</td></tr>'
                . '<tr><td>' . esc_html__('“Access from your network is blocked”', 'modify-login') . '</td><td>' . wp_kses(__('Your address is on the block list. Another administrator can remove it on the Brute force tab. Otherwise, from the database or WP-CLI, empty the list: <code>wp eval "\Authlify\Settings::update( array( \'ip_denylist\' => \'\' ) );"</code>', 'modify-login'), array('code' => array())) . '</td></tr>'
                . '<tr><td>' . esc_html__('The CAPTCHA will not let you through', 'modify-login') . '</td><td>' . wp_kses(__('Add <code>define( \'AUTHLIFY_DISABLE_CAPTCHA\', true );</code> to wp-config.php, log in, fix the keys or provider, then remove the line.', 'modify-login'), array('code' => array())) . '</td></tr>'
                . '<tr><td>' . esc_html__('Lost your phone and backup codes', 'modify-login') . '</td><td>' . wp_kses(__('Use the recovery email option on the two-factor screen if offered, ask another administrator to reset your two-factor, run <code>wp authlify reset_2fa you@example.com</code>, or add <code>define( \'AUTHLIFY_DISABLE_2FA\', true );</code> to wp-config.php.', 'modify-login'), array('code' => array())) . '</td></tr>'
                . '<tr><td>' . esc_html__('The login address shows “page not found” right after a change', 'modify-login') . '</td><td>' . esc_html__('A page cache may be serving an old copy. Purge the cache, or use AUTHLIFY_DISABLE_HIDE while you exclude the address.', 'modify-login') . '</td></tr>'
                . '</tbody></table></div>'
                . self::note(__('Remove any emergency line from wp-config.php as soon as you are back in; while it is there, that protection is off for everyone.', 'modify-login'), 'warning'),
                array('locked out', 'cannot login', 'emergency', 'recovery', 'unlock', 'disable')
            ),

            self::a('howto-brand-login', $c, __('Brand the login page', 'modify-login'), __('Make every login screen look like your site in a few minutes.', 'modify-login'),
                self::ul(array(
                    sprintf(__('Open %s.', 'modify-login'), self::go('modify-login-builder', __('Authlify → Designer', 'modify-login'))),
                    __('Click <strong>Match my site</strong> to start from your theme’s colors, font and logo, or pick a template.', 'modify-login'),
                    __('Adjust the sections on the left (colors, layout, background, logo, form, fields, button, links, messages). The preview updates as you go.', 'modify-login'),
                    __('Use the screen switcher above the preview to check the other screens, such as lost password and two-factor.', 'modify-login'),
                    __('Save. Open your login address in a private window to see the result.', 'modify-login'),
                ), true) . self::p(sprintf(__('Details: %s.', 'modify-login'), self::doc('designer', __('Login page designer', 'modify-login')))),
                array('brand', 'logo', 'customize login', 'colors', 'style', 'white label')
            ),

            self::a('howto-captcha-safely', $c, __('Add a CAPTCHA without blocking real people', 'modify-login'), __('Try a CAPTCHA in test mode first, then switch it on for real.', 'modify-login'),
                self::ul(array(
                    sprintf(__('Open %s and choose a provider. Cloudflare Turnstile suits most sites; ALTCHA needs no account and sends nothing elsewhere.', 'modify-login'), self::go('authlify-protection', __('Security → CAPTCHA', 'modify-login'), array('tab' => 'captcha'))),
                    __('Paste the site key and secret key (not needed for ALTCHA), click <strong>Show preview</strong> and complete it.', 'modify-login'),
                    __('Turn on <strong>Test mode</strong>, choose <strong>Only after failed logins</strong>, and save.', 'modify-login'),
                    __('After a few days, the Safety panel shows how many submissions would have been blocked in the last 7 days. Check the Activity log for “CAPTCHA failed” entries from real users.', 'modify-login'),
                    __('Turn test mode off.', 'modify-login'),
                ), true) . self::note(sprintf(__('If a CAPTCHA ever stops you logging in, add %s to wp-config.php.', 'modify-login'), "<code>define( 'AUTHLIFY_DISABLE_CAPTCHA', true );</code>")),
                array('captcha', 'test mode', 'turnstile', 'rollout')
            ),
        );
    }

    /* ----------------------------------------------------------------------
     * Troubleshooting
     * ------------------------------------------------------------------- */

    /**
     * Troubleshooting articles.
     *
     * @return array
     */
    private static function trouble_articles()
    {
        $c = 'trouble';

        return array(
            self::a('trouble-locked-out', $c, __('I am locked out', 'modify-login'), __('“Too many failed login attempts”, a blocked address, or a second step you cannot pass.', 'modify-login'),
                self::p(sprintf(__('Start with %s, which lists every situation and its way back in.', 'modify-login'), self::doc('howto-locked-out', __('Get back in when locked out', 'modify-login'))))
                . self::sections(array(
                    __('Why did it happen?', 'modify-login') => self::ul(array(
                        __('You (or someone sharing your internet connection) typed a wrong password too often. Offices, schools and mobile networks share addresses.', 'modify-login'),
                        __('Network lockouts are on and other addresses in your network failed too.', 'modify-login'),
                        __('Every visitor seems to come from one address because the site is behind a proxy or Cloudflare and the Visitor IP setting is wrong. Then a single attacker locks everyone out. See the proxy article.', 'modify-login'),
                    )),
                    __('Prevent it next time', 'modify-login') => self::ul(array(
                        __('Add your fixed IP address to “Never lock out”.', 'modify-login'),
                        __('Use a password manager, and set up a passkey so you rarely type the password.', 'modify-login'),
                        __('Turn on the lockout email to hear about administrator lockouts.', 'modify-login'),
                    )),
                )),
                array('locked out', 'too many failed login attempts', 'lockout', 'blocked', 'cannot log in')
            ),

            self::a('trouble-lost-url', $c, __('I lost the login address', 'modify-login'), __('Three ways to find or reset it without FTP.', 'modify-login'),
                self::ul(array(
                    __('Search your email for “Your login address”. It is sent to the site admin address whenever the address changes.', 'modify-login'),
                    __('Run <code>wp authlify url get</code> if you have WP-CLI or SSH access.', 'modify-login'),
                    __('Add <code>define( \'AUTHLIFY_DISABLE_HIDE\', true );</code> to wp-config.php, log in at <code>/wp-login.php</code>, check the address under Authlify → Login URL, then remove the line.', 'modify-login'),
                ), true) . self::p(__('If <code>AUTHLIFY_SLUG</code> is defined in wp-config.php, that value is the login address.', 'modify-login')),
                array('lost', 'forgot', 'login url', 'where is login', 'find login page')
            ),

            self::a('trouble-404-login', $c, __('The new login address shows “page not found”', 'modify-login'), __('Usually an unconfirmed change, a cache, or a server rule.', 'modify-login'),
                self::ul(array(
                    __('<strong>Not confirmed yet.</strong> A new address only works for 30 minutes before confirmation, and stops working if it expires. Open Authlify → Login URL (log in at the old address) and confirm or save it again.', 'modify-login'),
                    __('<strong>A cached copy.</strong> A page cache or CDN may have stored a “page not found” answer for that address before it existed. Purge the cache and exclude the address (Tools → Site Health lists the steps for the cache it finds).', 'modify-login'),
                    __('<strong>Plain permalinks.</strong> With plain permalinks the address is <code>https://example.com/?your-address</code>. The Login URL screen shows the exact form.', 'modify-login'),
                    __('<strong>Server rules.</strong> Some security rules on the web server (for example in .htaccess or nginx) block unknown paths or anything containing “login”. Authlify handles the address in WordPress itself, so the request must reach WordPress.', 'modify-login'),
                    __('<strong>Another plugin.</strong> A second “hide login” or security plugin may be changing the login address too. Authlify warns about the ones it knows on the Login URL screen.', 'modify-login'),
                )),
                array('404', 'not found', 'login url not working', 'page not found')
            ),

            self::a('trouble-cache', $c, __('Cache conflicts', 'modify-login'), __('A cached login page breaks logins and can show one visitor’s page to another.', 'modify-login'),
                self::sections(array(
                    __('What Authlify does', 'modify-login') => self::ul(array(
                        __('The login page sends “do not store” headers and sets <code>DONOTCACHEPAGE</code>, which most caching plugins respect.', 'modify-login'),
                        __('WP Rocket, LiteSpeed Cache, SiteGround Optimizer and Breeze are told to skip the login address automatically, including an address waiting for confirmation.', 'modify-login'),
                        __('Tools → Site Health → “Exclude the Authlify login URL from your page cache” appears when it detects a cache that needs a manual exclusion (for example W3 Total Cache, WP Super Cache, WP Engine, Kinsta or Cloudflare APO) and lists the steps.', 'modify-login'),
                    )),
                    __('Symptoms of a cached login page', 'modify-login') => self::ul(array(
                        __('“The link you followed has expired” or a failed login right after entering the right password.', 'modify-login'),
                        __('The login address shows “page not found” after you changed it.', 'modify-login'),
                        __('CAPTCHA widgets that never load or always fail.', 'modify-login'),
                    )),
                    __('Fix', 'modify-login') => self::p(__('Exclude <code>/your-address/</code> (and <code>/wp-login.php</code>) from every cache layer: the caching plugin, your host’s cache and the CDN. Then purge all caches.', 'modify-login')),
                )),
                array('cache', 'wp rocket', 'litespeed', 'w3 total cache', 'wp super cache', 'cloudflare', 'expired', 'cdn')
            ),

            self::a('trouble-captcha', $c, __('CAPTCHA not showing, or blocking people', 'modify-login'), __('Keys, caching, scripts and the reCAPTCHA v3 score.', 'modify-login'),
                self::sections(array(
                    __('Not showing', 'modify-login') => self::ul(array(
                        __('Check the form is ticked under <strong>Forms</strong>, and whether <strong>When to show it</strong> is “Only after failed logins”: then login forms only show it after failures.', 'modify-login'),
                        __('Addresses on “Never lock out” never see a CAPTCHA.', 'modify-login'),
                        __('<code>AUTHLIFY_DISABLE_CAPTCHA</code> in wp-config.php turns it off; the CAPTCHA tab says so.', 'modify-login'),
                        __('A script optimiser that delays or combines JavaScript can stop the widget loading. Exclude the provider’s script and the login address.', 'modify-login'),
                        __('WooCommerce forms only appear in the list when WooCommerce is active; the checkout option covers the classic checkout.', 'modify-login'),
                    )),
                    __('Blocking real people', 'modify-login') => self::ul(array(
                        __('Turn on <strong>Test mode</strong>: it logs failures without blocking while you investigate.', 'modify-login'),
                        __('For reCAPTCHA v3, lower the <strong>Minimum score</strong> (for example to 0.3).', 'modify-login'),
                        __('Make sure the site key is registered for this domain in the provider’s dashboard.', 'modify-login'),
                        __('Check the Activity log for “CAPTCHA failed” entries and their reason.', 'modify-login'),
                    )),
                    __('Locked yourself out', 'modify-login') => self::p(__('Add <code>define( \'AUTHLIFY_DISABLE_CAPTCHA\', true );</code> to wp-config.php, log in, fix the settings, and remove the line.', 'modify-login')),
                )),
                array('captcha', 'turnstile', 'recaptcha', 'hcaptcha', 'altcha', 'not showing', 'blocked', 'score')
            ),

            self::a('trouble-emails', $c, __('Emails are not arriving', 'modify-login'), __('Login address, unlock and two-factor recovery emails use WordPress’s normal mail.', 'modify-login'),
                self::ul(array(
                    __('Authlify sends email through <code>wp_mail()</code>, like the rest of WordPress. If password-reset emails do not arrive either, the problem is the site’s mail setup, not Authlify.', 'modify-login'),
                    __('Install an SMTP plugin, or use your host’s mail service, so mail is sent from an authenticated address. Check the spam folder.', 'modify-login'),
                    __('The login address email goes to the site admin address (Settings → General) and to the person who made the change.', 'modify-login'),
                    __('Unlock links go to the locked-out account’s own email address; at most three requests per hour per address are sent. Requests for unknown accounts are silently ignored.', 'modify-login'),
                    __('If mail is broken and you are locked out, use WP-CLI or the wp-config.php constants instead.', 'modify-login'),
                )),
                array('email', 'mail', 'smtp', 'not receiving', 'spam', 'unlock email')
            ),

            self::a('trouble-proxy', $c, __('Everyone gets locked out at once (proxies)', 'modify-login'), __('Behind Cloudflare, a load balancer or a host’s proxy, every visitor can look like the same address.', 'modify-login'),
                self::ul(array(
                    __('Open Security → Brute force and look at <strong>Detected setup</strong> and <strong>Your IP address as seen now</strong>. If that address is not your own public IP (for example it is 10.x, 172.16–31.x, 192.168.x, or a Cloudflare address), the setting is wrong.', 'modify-login'),
                    __('Choose <strong>Through Cloudflare</strong> for Cloudflare, or <strong>Through my own proxy</strong> and list the proxy addresses under <strong>Trusted proxies</strong> (your host can tell you them).', 'modify-login'),
                    __('Click <strong>Unlock everyone</strong> afterwards, or run <code>wp authlify unlock --all</code>.', 'modify-login'),
                    __('Tools → Site Health warns when the setting does not match the requests it sees.', 'modify-login'),
                ), true) . self::note(__('Never trust forwarding headers from everyone: attackers could then choose any address, including one on your “Never lock out” list. That is why Authlify only reads them from proxies you name.', 'modify-login'), 'warning'),
                array('proxy', 'load balancer', 'cloudflare', 'everyone locked out', 'same ip', 'x-forwarded-for', 'nginx')
            ),

            self::a('trouble-woocommerce', $c, __('WooCommerce', 'modify-login'), __('How Authlify works with the My Account login, checkout and registration.', 'modify-login'),
                self::ul(array(
                    __('The WooCommerce My Account login form keeps working and is not hidden. Brute-force lockouts apply to it, and the Activity log marks its logins with the “woocommerce” channel.', 'modify-login'),
                    __('CAPTCHA can protect the WooCommerce login, registration, lost password and classic checkout (guest orders) forms. The block-based checkout is not covered.', 'modify-login'),
                    __('The breached-password check also runs when customers register, change their password in My Account, or create an account at checkout.', 'modify-login'),
                    __('With Force login on, add your shop, cart, checkout and My Account paths to Public pages if customers must reach them.', 'modify-login'),
                    __('Customers never need wp-admin, so hiding /wp-admin/ does not affect them.', 'modify-login'),
                )),
                array('woocommerce', 'woo', 'shop', 'my account', 'checkout', 'customers')
            ),

            self::a('trouble-multisite', $c, __('Multisite', 'modify-login'), __('What changes when Authlify runs on a network.', 'modify-login'),
                self::ul(array(
                    __('Network-activate it to manage one set of settings for all sites from Network Admin → Authlify. Settings start from the main site’s settings. Only super admins can change them.', 'modify-login'),
                    __('The activity log, lockout counters and passkeys are stored in shared tables, so lockouts apply across the network and each site’s Activity screen shows entries from all sites.', 'modify-login'),
                    __('wp-signup.php and wp-activate.php are not hidden, because the network needs them.', 'modify-login'),
                    __('“Application passwords: Administrators only” means super admins on a network.', 'modify-login'),
                    __('The uninstall option is read from the network settings; when the plugin is activated per site rather than network-wide, deleting it does not remove data.', 'modify-login'),
                )),
                array('multisite', 'network', 'super admin', 'subsite')
            ),

            self::a('trouble-passkeys', $c, __('Passkeys are not offered', 'modify-login'), __('Passkeys need PHP 8, https, and a browser or device that supports them.', 'modify-login'),
                self::ul(array(
                    __('<strong>PHP 8.0 or newer.</strong> On older PHP, the passkey option is greyed out on the Two-factor screen with the PHP version shown. Ask your host to upgrade.', 'modify-login'),
                    __('<strong>https.</strong> Browsers only allow passkeys on secure pages (https, or localhost while developing).', 'modify-login'),
                    __('<strong>The same address.</strong> A passkey belongs to the site’s domain. After moving the site to a new domain, existing passkeys stop working and must be added again.', 'modify-login'),
                    __('<strong>The method is offered.</strong> “Passkeys and security keys” must be ticked under Offered methods on the Two-factor screen.', 'modify-login'),
                    __('<strong>The login button</strong> only appears once at least one person has added a passkey, and when “Passkey sign-in” is on.', 'modify-login'),
                )),
                array('passkey', 'webauthn', 'fido', 'security key', 'https', 'php 8', 'touch id', 'face id', 'windows hello')
            ),

            self::a('trouble-conflicts', $c, __('Conflicts with other security plugins', 'modify-login'), __('Which features overlap, and what to switch off.', 'modify-login'),
                self::ul(array(
                    __('<strong>Hide login plugins</strong> (WPS Hide Login, WP Ghost, and similar) and the “change login URL” feature of security suites: run only one. Authlify names the ones it detects on the Login URL screen. Import the address, then switch the other off.', 'modify-login'),
                    __('<strong>Two-factor plugins.</strong> When another two-factor plugin is active, Authlify steps aside: it adds no second step or passkey button, and the Two-factor screen says which plugin is in charge.', 'modify-login'),
                    __('<strong>Limit-login plugins and security suites</strong> (Wordfence, Solid Security, All In One WP Security and others) can run alongside, but two sets of lockouts and two CAPTCHAs confuse people. Keep one of each.', 'modify-login'),
                    __('<strong>Login customizers</strong> such as LoginPress: import the design into Authlify’s Designer, then deactivate them.', 'modify-login'),
                    __('<strong>Server firewalls</strong> (mod_security, host rules) may block the login address or CAPTCHA requests. Check the host’s logs if a request never reaches WordPress.', 'modify-login'),
                )),
                array('conflict', 'wordfence', 'solid security', 'ithemes', 'aiowps', 'two factor plugin', 'wp 2fa', 'compatibility')
            ),
        );
    }

    /* ----------------------------------------------------------------------
     * FAQ
     * ------------------------------------------------------------------- */

    /**
     * Frequently asked questions.
     *
     * @return array
     */
    private static function faq_articles()
    {
        $c = 'faq';

        return array(
            self::a('faq-free-vs-pro', $c, __('What is the difference between Free and Pro?', 'modify-login'), __('Everything described under “Free features” is free for good. Pro adds tools for teams, stores and agencies.', 'modify-login'),
                self::p(__('The free plugin includes the private login address, Leak Check, brute-force lockouts, all CAPTCHA providers and the honeypot, two-factor login with authenticator apps, backup codes and passkeys, the breached-password check, hardening, redirects, the activity log, the login page designer, import and export, and WP-CLI. There are no usage limits and no license.', 'modify-login'))
                . self::p(__('Authlify Pro adds:', 'modify-login'))
                . self::ul(array(
                    __('Two-factor rules: required two-factor for chosen roles with a grace period, email codes, trusted devices, sudo mode and a coverage report.', 'modify-login'),
                    __('Passwordless sign-in: login links, email sign-in codes and temporary access links.', 'modify-login'),
                    __('Social and single sign-on: Google, Microsoft, Apple, GitHub and any OpenID Connect provider.', 'modify-login'),
                    __('Alerts and monitoring: new-device and new-country alerts, Slack, Discord, Telegram and webhooks, a local country database.', 'modify-login'),
                    __('Sessions, password policy and access rules by country, time or IP.', 'modify-login'),
                    __('Premium templates, branded emails, login blocks, a popup login and a WooCommerce My Account skin.', 'modify-login'),
                    __('Agency tools: network-wide settings with locks, white-label, client handoff and design sync, plus a REST API.', 'modify-login'),
                )) . self::p(sprintf(__('Each is described under %s.', 'modify-login'), self::doc('pro-2fa-policies', __('Pro features', 'modify-login')))),
                array('pro', 'premium', 'pricing', 'upgrade', 'compare', 'free')
            ),

            self::a('faq-compatibility', $c, __('Is it compatible with my site?', 'modify-login'), __('Requirements, and how it works with common plugins and hosts.', 'modify-login'),
                self::ul(array(
                    __('WordPress 6.4 or newer and PHP 7.4 or newer. Passkeys need PHP 8.0.', 'modify-login'),
                    __('Single sites and multisite networks.', 'modify-login'),
                    __('WooCommerce: lockouts, CAPTCHA, two-factor and the breached-password check cover its forms.', 'modify-login'),
                    __('Caching plugins: WP Rocket, LiteSpeed Cache, SiteGround Optimizer and Breeze are configured automatically; Site Health gives steps for others.', 'modify-login'),
                    __('Cloudflare and reverse proxies: supported through the Visitor IP setting.', 'modify-login'),
                    __('Other two-factor plugins: Authlify steps aside when Two Factor, WP 2FA or Wordfence Login Security is active.', 'modify-login'),
                    __('Other “hide login” plugins: run only one; Authlify can import their address.', 'modify-login'),
                )),
                array('compatible', 'requirements', 'woocommerce', 'multisite', 'php', 'cache', 'cloudflare')
            ),

            self::a('faq-data', $c, __('Does Authlify send my data anywhere?', 'modify-login'), __('No, unless you switch on a feature that needs a service, and then only what that feature needs.', 'modify-login'),
                self::p(__('The activity log, lockouts and two-factor data stay in your database. Authlify contacts another service only for a CAPTCHA provider you choose, and for the breached-password check if you turn it on. It loads no fonts, scripts or images from a CDN on its own.', 'modify-login'))
                . self::p(sprintf(__('The full list is in %s.', 'modify-login'), self::doc('privacy-external', __('External services', 'modify-login')))),
                array('privacy', 'gdpr', 'data', 'third party', 'tracking', 'phone home', 'telemetry')
            ),

            self::a('faq-security', $c, __('Is hiding the login page real security?', 'modify-login'), __('It removes the noise; lockouts, CAPTCHA and two-factor provide the protection.', 'modify-login'),
                self::p(__('A private login address stops the automated scripts that only know wp-login.php, which is most of them, and Leak Check proves it is not revealed. But an address is not a password: anyone who learns it can try to log in. That is why Authlify also limits attempts, supports CAPTCHA, checks for breached passwords and offers two-factor login and passkeys. Use them together.', 'modify-login')),
                array('security', 'obscurity', 'safe', 'protection', 'hide login')
            ),

            self::a('faq-updates', $c, __('How do updates work?', 'modify-login'), __('Through WordPress.org, like any free plugin.', 'modify-login'),
                self::ul(array(
                    __('Updates appear under Dashboard → Updates and on the Plugins screen, and work with automatic updates.', 'modify-login'),
                    __('Settings, the log and two-factor data are kept; any database changes run on their own after the update.', 'modify-login'),
                    __('Authlify Pro is updated separately from its own update server while its license is active. Its features keep working when a license lapses.', 'modify-login'),
                )),
                array('update', 'upgrade', 'version', 'auto update')
            ),

            self::a('faq-upgrade-from-modify-login', $c, __('I used Modify Login. What changed?', 'modify-login'), __('Modify Login is now Authlify. Your login address, redirects and log carry over.', 'modify-login'),
                self::ul(array(
                    __('The login address, the hiding and redirect behavior, login and logout redirects, activity logging and reCAPTCHA v2 keys (on the login form) carry over. The newest 50,000 log entries are copied; the old log table is kept.', 'modify-login'),
                    __('Your old login page design is kept as a design in the Designer.', 'modify-login'),
                    __('New protection (brute-force lockouts, two-factor login, the weekly Leak Check, the honeypot, the other CAPTCHA forms) stays off until you switch it on. The notice “Modify Login is now Authlify” links to the dashboard to review it.', 'modify-login'),
                    __('If the old address had characters that are no longer allowed, the notice shows the new address.', 'modify-login'),
                    __('Old bookmarks to the Modify Login screens still open the right Authlify screens.', 'modify-login'),
                )),
                array('modify login', 'upgrade', 'migration', '2.x', 'rename', 'old version')
            ),

            self::a('faq-uninstall', $c, __('What happens when I deactivate or delete it?', 'modify-login'), __('Deactivating keeps everything. Deleting removes data only if you asked it to.', 'modify-login'),
                self::ul(array(
                    __('<strong>Deactivate</strong>: the login page goes back to wp-login.php at once, lockouts and two-factor stop, and all settings and data are kept for when you reactivate.', 'modify-login'),
                    __('<strong>Delete</strong>: by default, settings and data are kept so a reinstall picks up where you left off.', 'modify-login'),
                    __('To remove everything, turn on <strong>Authlify → Settings → Data → On uninstall</strong> before deleting. Deleting then removes the three Authlify tables, every Authlify option and user meta (including two-factor secrets and passkeys), the compiled login styles and the scheduled tasks. Old Modify Login options and log are removed too.', 'modify-login'),
                    __('<strong>Multisite</strong>: when Authlify was activated site by site, each site\'s own "On uninstall" choice applies. Sites that asked are cleaned (settings, design files, scheduled tasks and their log entries); the shared tables stay while any site keeps its data. When it was network-activated, the network setting decides for every site.', 'modify-login'),
                )),
                array('uninstall', 'delete', 'remove', 'deactivate', 'cleanup', 'multisite')
            ),

            self::a('faq-downgrade', $c, __('Can I go back to Modify Login 2.x?', 'modify-login'), __('Yes, nothing is lost, but the old version only knows its own settings.', 'modify-login'),
                self::ul(array(
                    __('Installing Modify Login 2.x over Authlify works and deletes nothing. All Authlify settings, logs, two-factor data and designs stay in the database.', 'modify-login'),
                    __('2.x reads its own old settings, so the <strong>login URL goes back to the one you had in 2.x</strong>. A login URL you chose in Authlify stops working (it shows "page not found") until you update again. Note your 2.x login address before you roll back.', 'modify-login'),
                    __('Everything 2.x does not have stops while it is active: lockouts, two-factor login, passkeys, the honeypot and CAPTCHA providers other than reCAPTCHA.', 'modify-login'),
                    __('Updating to Authlify again picks up exactly where you left off. Changes you made in 2.x in the meantime are not copied over again.', 'modify-login'),
                    __('If you are locked out after a rollback, the 2.x login address or <code>wp-login.php</code> (if 2.x was not hiding it) gets you in.', 'modify-login'),
                )),
                array('downgrade', 'rollback', 'roll back', 'modify login', '2.x', 'old version')
            ),
        );
    }

    /* ----------------------------------------------------------------------
     * Security and privacy
     * ------------------------------------------------------------------- */

    /**
     * Security and privacy articles.
     *
     * @return array
     */
    private static function privacy_articles()
    {
        $c = 'privacy';

        return array(
            self::a('privacy-data-stored', $c, __('What Authlify stores', 'modify-login'), __('Every table, option and user field the free plugin writes.', 'modify-login'),
                self::sections(array(
                    __('Database tables', 'modify-login') => '<dl>'
                        . '<dt><code>{prefix}authlify_log</code></dt><dd>' . esc_html__('The activity log: time (UTC), event, user ID, username, IP address (shortened if you anonymize), two-letter country when known, browser (user agent) and details such as the login channel or failure reason.', 'modify-login') . '</dd>'
                        . '<dt><code>{prefix}authlify_limits</code></dt><dd>' . esc_html__('Lockout counters: the IP address, network or account, the number of recent failures, the time of the last failure and any lockout end time. Rows older than a day without a lockout are removed daily.', 'modify-login') . '</dd>'
                        . '<dt><code>{prefix}authlify_passkeys</code></dt><dd>' . esc_html__('Registered passkeys: user ID, site domain, credential ID, public key, signature counter, the name the person gave it, and when it was added and last used. Removed when the user is deleted.', 'modify-login') . '</dd>'
                        . '</dl>' . self::p(__('On multisite these tables are shared by the network.', 'modify-login')),
                    __('Options', 'modify-login') => self::p(__('<code>authlify_settings</code> (all settings; a network option when network-activated), <code>authlify_design</code> (the login page design), <code>authlify_version</code>, <code>authlify_db_version</code>, <code>authlify_leak_check</code> (the last result), <code>authlify_log_error</code> (only if writing to the log failed), and on upgraded sites <code>authlify_migrated_from</code> and related markers. Short-lived transients hold rate limits, unlock and recovery tokens, and drafts.', 'modify-login')),
                    __('User meta', 'modify-login') => self::p(__('Only for people who use two-factor or dismiss a notice: <code>authlify_2fa</code> (on/off flag), <code>authlify_totp_secret</code> (encrypted), <code>authlify_totp_pending</code>, <code>authlify_totp_last_step</code>, <code>authlify_backup_codes</code> (hashed), <code>authlify_backup_codes_created</code>, <code>authlify_passkey_handle</code>, <code>authlify_passkey_register</code>, <code>authlify_2fa_login</code> (a hashed, 10-minute sign-in token), <code>authlify_2fa_recovery</code> (a hashed recovery token), <code>authlify_2fa_coexist_dismissed</code> and <code>authlify_seen_welcome</code>.', 'modify-login')),
                    __('Files', 'modify-login') => self::p(__('<code>wp-content/uploads/authlify/</code> holds the compiled login page CSS.', 'modify-login')),
                    __('What is not stored', 'modify-login') => self::p(__('Passwords are never stored or logged by Authlify, including failed ones. The CAPTCHA answers and the breached-password lookups are not stored either (only the public breach list for a hash prefix is cached for a day).', 'modify-login')),
                )),
                array('data', 'tables', 'database', 'options', 'user meta', 'gdpr', 'storage')
            ),

            self::a('privacy-retention', $c, __('Retention, anonymization and personal data requests', 'modify-login'), __('How long the log is kept, how to shorten IP addresses, and how WordPress’s privacy tools cover Authlify.', 'modify-login'),
                self::ul(array(
                    sprintf(__('<strong>Retention.</strong> Log entries are deleted after 90 days by default (%s → Keep entries for). 0 keeps them forever. Deletion runs daily.', 'modify-login'), self::go('modify-login-logs', __('Activity → Settings', 'modify-login'), array('tab' => 'settings'))),
                    __('<strong>Anonymize IPs.</strong> Stores IPv4 addresses with the last part set to 0 and keeps only the first half of IPv6 addresses. It applies to new entries. Lockouts still use full addresses, briefly, in the lockout counters.', 'modify-login'),
                    __('<strong>Turn logging off</strong> with the Activity log switch; lockouts keep working. Login address changes are always recorded.', 'modify-login'),
                    __('<strong>Export and erase.</strong> Authlify registers “Authlify login activity” with WordPress’s Export Personal Data and Erase Personal Data tools (Tools menu). They cover log entries linked to the person’s user account; failed attempts made with a username that does not exist are not linked to anyone.', 'modify-login'),
                    __('<strong>Privacy policy.</strong> Authlify adds suggested text to Settings → Privacy → Policy guide.', 'modify-login'),
                    __('<strong>Delete everything.</strong> See “What happens when I deactivate or delete it?”.', 'modify-login'),
                )),
                array('gdpr', 'retention', 'anonymize', 'erase', 'export personal data', 'privacy policy', 'ccpa')
            ),

            self::a('privacy-external', $c, __('External services', 'modify-login'), __('Which outside services can be contacted, when, and what they receive. Nothing is contacted unless you turn on the feature that needs it.', 'modify-login'),
                self::sections(array(
                    __('Free plugin', 'modify-login') => '<dl>'
                        . '<dt>' . esc_html__('CAPTCHA provider (only the one you choose)', 'modify-login') . '</dt><dd>' . esc_html__('Cloudflare Turnstile (challenges.cloudflare.com), hCaptcha (js.hcaptcha.com, api.hcaptcha.com) or Google reCAPTCHA (www.google.com). The visitor’s browser loads the widget on protected forms; your server sends the answer, your secret key and the visitor’s IP address to the provider to check it. ALTCHA contacts nobody. Nothing is contacted while the provider is “None”.', 'modify-login') . '</dd>'
                        . '<dt>' . esc_html__('Have I Been Pwned (only with the breached-password check on)', 'modify-login') . '</dt><dd>' . esc_html__('api.pwnedpasswords.com receives the first 5 characters of the SHA-1 hash of a new password, never the password.', 'modify-login') . '</dd>'
                        . '<dt>' . esc_html__('Your own site (Leak Check)', 'modify-login') . '</dt><dd>' . esc_html__('Leak Check sends requests only to your own site’s address, while a custom login address is set.', 'modify-login') . '</dd>'
                        . '</dl>' . self::p(__('The free plugin loads no fonts, scripts, images or avatars from other services on its own, and sends no usage data. Emails are sent through your site’s own mail setup.', 'modify-login')),
                    __('Authlify Pro (when installed)', 'modify-login') => '<dl>'
                        . '<dt>' . esc_html__('License and update server', 'modify-login') . '</dt><dd>' . esc_html__('store.mantrabrain.com. With a license key saved: when you activate, refresh or deactivate it, and in a background update check about every 12 hours. Without a key, only when you click “Check for updates” on the License screen or “View details” on the Plugins screen. It sends the license key (if saved), the site address, the Pro version and the environment type (production, staging and so on).', 'modify-login') . '</dd>'
                        . '<dt>' . esc_html__('DB-IP (only if you choose the local country database)', 'modify-login') . '</dt><dd>' . esc_html__('The free DB-IP Lite country file is downloaded from download.db-ip.com about once a month. Lookups then happen on your server; no visitor address is sent.', 'modify-login') . '</dd>'
                        . '<dt>' . esc_html__('Social and single sign-on providers (only those you enable)', 'modify-login') . '</dt><dd>' . esc_html__('Google, Microsoft, Apple, GitHub or your OpenID Connect provider, when someone signs in with them: your server exchanges the sign-in code and reads the person’s identity and email.', 'modify-login') . '</dd>'
                        . '<dt>' . esc_html__('Alert channels (only those you add)', 'modify-login') . '</dt><dd>' . esc_html__('Slack, Discord, Microsoft Teams, Telegram (api.telegram.org) or your own webhook address receive alert messages, which can include a username, IP address and country.', 'modify-login') . '</dd>'
                        . '<dt>' . esc_html__('Your other sites (design sync, only when you click)', 'modify-login') . '</dt><dd>' . esc_html__('Pulling or pushing a login design contacts the other site you name, with its sync key.', 'modify-login') . '</dd>'
                        . '</dl>',
                )),
                array('third party', 'external', 'gdpr', 'cloudflare', 'google', 'hcaptcha', 'hibp', 'db-ip', 'licence', 'phone home', 'requests', 'gravatar')
            ),

            self::a('privacy-permissions', $c, __('Permissions and sensitive data', 'modify-login'), __('Who can change what, and how secrets are protected.', 'modify-login'),
                self::sections(array(
                    __('Permissions', 'modify-login') => self::ul(array(
                        __('Every Authlify screen and setting needs the <code>manage_options</code> capability (administrators), or <code>manage_network_options</code> (super admins) when network-activated. Every change is protected by a WordPress nonce.', 'modify-login'),
                        __('Each person manages only their own two-factor methods. Administrators who can edit a user can see whether that user has two-factor and reset it, but never see their secrets or codes.', 'modify-login'),
                        __('The emergency constants and WP-CLI commands need access to the server, which is the point: they work when the admin screens cannot be reached.', 'modify-login'),
                    )),
                    __('How secrets are kept', 'modify-login') => self::ul(array(
                        __('Authenticator secrets are encrypted (libsodium) with a key derived from your wp-config.php security keys. Without the sodium extension they are stored unencrypted and Site Health warns you.', 'modify-login'),
                        __('Backup codes are stored hashed and removed once used. Unlock, recovery and sign-in tokens are stored only as hashes, expire within minutes, and work once.', 'modify-login'),
                        __('Passkeys store only public keys; the private key never leaves the device.', 'modify-login'),
                        __('The CAPTCHA secret key is stored in the settings like other WordPress plugin keys, and is never included in exports.', 'modify-login'),
                        __('Settings exports contain the login address; keep them private.', 'modify-login'),
                    )),
                    __('Reporting a security issue', 'modify-login') => self::p(__('Please report vulnerabilities privately to the plugin author through the contact details on the plugin’s WordPress.org page rather than in public support threads.', 'modify-login')),
                )),
                array('permissions', 'capability', 'manage_options', 'encryption', 'secrets', 'security', 'nonce')
            ),
        );
    }

    /* ----------------------------------------------------------------------
     * Developers
     * ------------------------------------------------------------------- */

    /**
     * A hook reference list.
     *
     * @param array $hooks array( name, type, params, description ).
     * @return string
     */
    private static function hooks(array $hooks)
    {
        $html = '<dl>';
        foreach ($hooks as $hook) {
            $html .= '<dt><code>' . esc_html($hook[0]) . '</code> <span class="authlify-docs__hook-type">' . esc_html($hook[1]) . '</span></dt>'
                . '<dd>' . ('' !== $hook[2] ? '<code>' . esc_html($hook[2]) . '</code><br>' : '') . esc_html($hook[3]) . '</dd>';
        }

        return $html . '</dl>';
    }

    /**
     * Developer articles.
     *
     * @return array
     */
    private static function dev_articles()
    {
        $c = 'dev';
        $filter = __('filter', 'modify-login');
        $action = __('action', 'modify-login');

        return array(
            self::a('dev-constants', $c, __('Constants', 'modify-login'), __('Switches you can set in wp-config.php.', 'modify-login'),
                self::hooks(array(
                    array('AUTHLIFY_SLUG', 'string', "define( 'AUTHLIFY_SLUG', 'my-door' );", __('Forces the login address. Must be 3–64 lowercase letters, numbers, hyphens or underscores and not reserved; otherwise it is ignored. Locks the Login URL field and wp authlify url set.', 'modify-login')),
                    array('AUTHLIFY_DISABLE_HIDE', 'bool', "define( 'AUTHLIFY_DISABLE_HIDE', true );", __('Turns the custom login address off (wp-login.php works again). Wins over AUTHLIFY_SLUG. Recovery switch.', 'modify-login')),
                    array('AUTHLIFY_DISABLE_CAPTCHA', 'bool', "define( 'AUTHLIFY_DISABLE_CAPTCHA', true );", __('Turns every CAPTCHA off. Recovery switch.', 'modify-login')),
                    array('AUTHLIFY_DISABLE_2FA', 'bool', "define( 'AUTHLIFY_DISABLE_2FA', true );", __('Turns two-factor login off for everyone; existing setups are kept. Recovery switch.', 'modify-login')),
                )) . self::note(__('Recovery switches weaken the site while they are set. Remove them as soon as you are back in.', 'modify-login'), 'warning')
                . self::p(__('Read-only constants defined by the plugin: <code>AUTHLIFY_VERSION</code>, <code>AUTHLIFY_FILE</code>, <code>AUTHLIFY_DIR</code>, <code>AUTHLIFY_URL</code>, <code>AUTHLIFY_BASENAME</code>, and the 2.x aliases <code>MODIFY_LOGIN_VERSION</code>, <code>MODIFY_LOGIN_FILE</code>, <code>MODIFY_LOGIN_PATH</code>, <code>MODIFY_LOGIN_URL</code>, <code>MODIFY_LOGIN_BASENAME</code>. <code>AUTHLIFY_PRO_VERSION</code> is defined when Authlify Pro is active.', 'modify-login')),
                array('wp-config', 'define', 'constant', 'AUTHLIFY_SLUG', 'AUTHLIFY_DISABLE_HIDE', 'AUTHLIFY_DISABLE_CAPTCHA', 'AUTHLIFY_DISABLE_2FA')
            ),

            self::a('dev-hooks', $c, __('Actions and filters', 'modify-login'), __('Public hooks for integrations: events, settings, login address, lockouts, two-factor and the designer.', 'modify-login'),
                self::p(__('All hooks start with <code>authlify_</code>. The ones below are stable for integrations. Internal admin-screen hooks are not listed and may change.', 'modify-login'))
                . '<h3>' . esc_html__('Events', 'modify-login') . '</h3>' . self::hooks(array(
                    array('authlify_logged', $action, '$event, array $row, int $id', __('After an activity log entry is written. The best feed for a SIEM or chat alerts.', 'modify-login')),
                    array('authlify_lockout', $action, 'string $scope, string $subject, int $until, string $username', __('An address (scope ip) or network (scope net) was locked until $until.', 'modify-login')),
                    array('authlify_blocked_request', $action, '', __('A hidden login or admin address was requested by a visitor who is not logged in.', 'modify-login')),
                    array('authlify_twofactor_verified', $action, 'WP_User $user, string $method', __('The second step succeeded, just before the login completes.', 'modify-login')),
                    array('authlify_passkeys_changed', $action, 'int $user_id', __('A passkey was added or removed.', 'modify-login')),
                    array('authlify_leak_check_done', $action, 'array $result', __('A Leak Check run finished.', 'modify-login')),
                    array('authlify_settings_updated', $action, 'array $new, array $old', __('After settings are saved from any screen, import or command.', 'modify-login')),
                    array('authlify_upgraded', $action, 'string $from, string $to', __('After an update. $from is empty on the first run.', 'modify-login')),
                    array('authlify_loaded', $action, '', __('Authlify has loaded (on plugins_loaded). Add-ons hook in here.', 'modify-login')),
                    array('authlify_reset_two_factor', $action, 'int $user_id', __('Fire it to remove all of a person’s two-factor methods; listen to it to clear your own method’s data.', 'modify-login')),
                    array('authlify_design_saved', $action, 'array $design, array $old', __('The login page design was saved.', 'modify-login')),
                    array('authlify_imported', $action, 'array $data', __('A settings file was imported.', 'modify-login')),
                ))
                . '<h3>' . esc_html__('Settings and data', 'modify-login') . '</h3>' . self::hooks(array(
                    array('authlify_settings_schema', $filter, 'array $schema', __('Declare your own settings keys: key => array( type, default [, choices] ). Types: bool, int, string, slug, url, text, choice, list, map.', 'modify-login')),
                    array('authlify_validate_settings', $filter, 'array $values, string $page, string $tab', __('Validate a settings form before saving. Return a WP_Error to refuse.', 'modify-login')),
                    array('authlify_log_events', $filter, 'array $events', __('Register labels for your own log events (key => label), then write them with \Authlify\Log\Log::add().', 'modify-login')),
                    array('authlify_export_data', $filter, 'array $data', __('Add your data to the settings export file.', 'modify-login')),
                    array('authlify_importers', $filter, 'array $importers', __('Offer an import from another plugin: key => array( label, detect callable, run callable returning a summary ).', 'modify-login')),
                    array('authlify_install_tables', $filter, 'array $sql, string $prefix, string $charset', __('Add CREATE TABLE statements; tables are created on activation and upgrade.', 'modify-login')),
                ))
                . '<h3>' . esc_html__('Login address and redirects', 'modify-login') . '</h3>' . self::hooks(array(
                    array('authlify_redirect_url', $filter, 'string $url, WP_User $user, string $type', __('The “everyone” redirect after login or logout ($type). Empty means the WordPress default. Role rules are applied separately.', 'modify-login')),
                    array('authlify_logged_in_redirect', $filter, 'string $to', __('Where a logged-in visitor to the bare login address is sent.', 'modify-login')),
                    array('authlify_admin_404_url', $filter, 'string $url', __('Where hidden /wp-admin/ requests go in “page not found” mode. Default home_url( \'/404\' ).', 'modify-login')),
                    array('authlify_login_url_email', $filter, 'array $email, string $url', __('The email sent when the login address changes: to, subject, message.', 'modify-login')),
                    array('authlify_lockout_message', $filter, 'string $message, int $until', __('The message a locked-out visitor sees (HTML).', 'modify-login')),
                    array('authlify_unlock_by_email', $filter, 'bool $show', __('Return false to remove “Email me an unlock link” from the lockout message.', 'modify-login')),
                    array('authlify_admin_lockout_email', $filter, 'bool $send', __('Return false to stop the administrator-lockout email.', 'modify-login')),
                ))
                . '<h3>' . esc_html__('CAPTCHA', 'modify-login') . '</h3>' . self::hooks(array(
                    array('authlify_captcha_theme', $filter, 'string $theme', __('auto, light or dark.', 'modify-login')),
                    array('authlify_captcha_message', $filter, 'string $text, string $form, string $reason', __('The error shown when the check fails.', 'modify-login')),
                    array('authlify_captcha_timeout', $filter, 'int $seconds, string $provider', __('How long to wait for the provider’s verification (default 8).', 'modify-login')),
                ))
                . '<h3>' . esc_html__('Two-factor', 'modify-login') . '</h3>' . self::hooks(array(
                    array('authlify_twofactor_methods', $filter, 'array $methods', __('Register an extra second-step method. Your verify callback is part of the login’s security; review it as such.', 'modify-login')),
                    array('authlify_twofactor_settings_url', $filter, 'string $url, WP_User $user', __('Where people set up two-factor (default: their profile).', 'modify-login')),
                    array('authlify_totp_issuer', $filter, 'string $issuer, WP_User $user', __('The name shown in authenticator apps.', 'modify-login')),
                    array('authlify_twofactor_recovery_enabled', $filter, 'bool $enabled', __('Return false to turn off the recovery email option.', 'modify-login')),
                ))
                . '<h3>' . esc_html__('Designer', 'modify-login') . '</h3>' . self::hooks(array(
                    array('authlify_design_templates', $filter, 'array $templates', __('Add login page templates.', 'modify-login')),
                    array('authlify_design_schema', $filter, 'array $schema', __('Add design fields.', 'modify-login')),
                    array('authlify_design_css', $filter, 'string $css, array $design', __('Adjust the compiled login page CSS.', 'modify-login')),
                    array('authlify_design_match_site', $filter, 'array $design', __('Adjust what “Match my site” produces.', 'modify-login')),
                ))
                . self::note(__('Some hooks can switch protection off (for example by trusting a different client IP, skipping a CAPTCHA or two-factor step, or opening a private site). They exist for specific integrations, are documented in the code, and are deliberately not listed here. Treat any code that uses them as security-critical.', 'modify-login'), 'warning'),
                array('hooks', 'actions', 'filters', 'add_filter', 'add_action', 'api', 'integration', 'siem')
            ),

            self::a('dev-example-log', $c, __('Example: send login events elsewhere', 'modify-login'), __('Forward failed logins and lockouts to your own system with one action.', 'modify-login'),
                self::p(__('Put this in a small plugin or mu-plugin. It runs after each activity log entry is written, so it also respects the log settings.', 'modify-login'))
                . self::code("add_action( 'authlify_logged', function ( \$event, \$row, \$id ) {\n    if ( ! in_array( \$event, array( 'login_failed', 'lockout' ), true ) ) {\n        return;\n    }\n    wp_remote_post( 'https://siem.example.com/ingest', array(\n        'timeout'  => 3,\n        'blocking' => false,\n        'body'     => wp_json_encode( array(\n            'event'    => \$event,\n            'username' => \$row['username'],\n            'ip'       => \$row['ip'],\n            'time'     => \$row['created_at'],\n        ) ),\n    ) );\n}, 10, 3 );")
                . self::p(__('To record your own events, register a label and write an entry:', 'modify-login'))
                . self::code("add_filter( 'authlify_log_events', function ( \$events ) {\n    \$events['my_sso_login'] = __( 'Signed in with company SSO', 'my-plugin' );\n    return \$events;\n} );\n\n\\Authlify\\Log\\Log::add( 'my_sso_login', array(\n    'user_id'  => \$user->ID,\n    'username' => \$user->user_login,\n    'context'  => array( 'provider' => 'acme' ),\n) );"),
                array('example', 'code', 'siem', 'webhook', 'log', 'integration')
            ),

            self::a('dev-rest', $c, __('REST API', 'modify-login'), __('Which REST routes exist, and which are meant for integrations.', 'modify-login'),
                self::p(__('The free plugin registers routes under <code>/wp-json/authlify/v1/</code> for its own screens: the two-factor profile section (<code>/twofactor/…</code>) and the login page designer (<code>/designer/…</code>). They require a logged-in user with the right permissions and are internal: their shape can change between versions, so do not build integrations on them.', 'modify-login'))
                . self::p(sprintf(__('For integrations (reading settings and activity, managing lockouts, syncing designs), Authlify Pro provides a documented, versioned REST API. See %s.', 'modify-login'), self::doc('pro-rest-api', __('Pro REST API', 'modify-login'))))
                . self::p(__('Hardening can hide the core <code>/wp/v2/users</code> routes from visitors, and Force login blocks anonymous REST requests.', 'modify-login')),
                array('rest', 'api', 'wp-json', 'endpoint', 'route')
            ),

            self::a('dev-extend', $c, __('Extending Authlify', 'modify-login'), __('Add settings, screens, security tabs, dashboard checks, importers, designer panels and docs articles.', 'modify-login'),
                self::sections(array(
                    __('Settings', 'modify-login') => self::p(__('Declare keys with <code>authlify_settings_schema</code>. Values live in the single <code>authlify_settings</code> option (a network option when network-activated), are sanitized by type, and are included in exports automatically. Read them with <code>\Authlify\Settings::get( $key )</code> and save with <code>\Authlify\Settings::update( array( … ) )</code>. Keys no loaded code declares are kept but never used.', 'modify-login')),
                    __('Screens', 'modify-login') => self::p(__('<code>authlify_admin_pages</code> adds a page (key => array( slug, menu title, callable, position )). <code>authlify_protection_tabs</code> adds a Security tab, <code>authlify_activity_tabs</code> an Activity tab, and <code>authlify_dashboard_checks</code> a checklist item (key => array( done, title, text, url )). Build forms with the <code>\Authlify\Admin\UI</code> helpers so they match the rest of the plugin; the shared form saver sanitizes by schema.', 'modify-login')),
                    __('Designer panels (JavaScript)', 'modify-login') => self::p(__('The designer uses <code>wp.hooks</code>: <code>authlify.designer.sections</code> adds a panel, <code>authlify.designer.sectionBefore</code> adds a note at the top of a panel, and <code>authlify.designer.structure</code> lists design values that change the page markup.', 'modify-login')) . self::code("wp.hooks.addFilter( 'authlify.designer.sections', 'my-plugin', ( sections ) => [\n    ...sections,\n    { name: 'my-panel', title: 'My panel', order: 35, render: ( { design, set, controls } ) => null },\n] );"),
                    __('Documentation', 'modify-login') => self::p(__('Add articles to this screen with <code>authlify_docs_articles</code>: id => array( id, category, title, summary, body, pro, keywords ). Categories: start, features, pro, config, howto, trouble, faq, privacy, dev.', 'modify-login')),
                )),
                array('extend', 'add-on', 'addon', 'custom', 'schema', 'admin page', 'wp.hooks', 'designer')
            ),
        );
    }
}
