<?php
/**
 * Plugin Name: Publications Filter System
 * Description: Creates a custom post type "Publications" with advanced filtering capabilities
 * Version: 1.0.0
 * Author: Your Name
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PublicationsFilterPlugin {
    
    public function __construct() {
        add_action('init', array($this, 'create_publications_post_type'));
        add_action('init', array($this, 'create_publications_taxonomies'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_get_filtered_publications', array($this, 'get_filtered_publications'));
        add_action('wp_ajax_nopriv_get_filtered_publications', array($this, 'get_filtered_publications'));
        add_shortcode('publications_filter', array($this, 'publications_filter_shortcode'));
    }
    
    /**
     * Create Publications Custom Post Type
     */
    public function create_publications_post_type() {
        $labels = array(
            'name' => 'Publications',
            'singular_name' => 'Publication',
            'menu_name' => 'Publications',
            'add_new' => 'Add New Publication',
            'add_new_item' => 'Add New Publication',
            'edit_item' => 'Edit Publication',
            'new_item' => 'New Publication',
            'view_item' => 'View Publication',
            'search_items' => 'Search Publications',
            'not_found' => 'No publications found',
            'not_found_in_trash' => 'No publications found in trash'
        );
        
        $args = array(
            'labels' => $labels,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'publications'),
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => 5,
            'menu_icon' => 'dashicons-book-alt',
            'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'author', 'custom-fields'),
            'show_in_rest' => true,
        );
        
        register_post_type('publication', $args);
    }
    
    /**
     * Create Custom Taxonomies for Publications
     */
    public function create_publications_taxonomies() {
        // Publications Categories
        $category_labels = array(
            'name' => 'Publication Categories',
            'singular_name' => 'Publication Category',
            'search_items' => 'Search Categories',
            'all_items' => 'All Categories',
            'parent_item' => 'Parent Category',
            'parent_item_colon' => 'Parent Category:',
            'edit_item' => 'Edit Category',
            'update_item' => 'Update Category',
            'add_new_item' => 'Add New Category',
            'new_item_name' => 'New Category Name',
            'menu_name' => 'Categories',
        );
        
        register_taxonomy('publication_category', array('publication'), array(
            'hierarchical' => true,
            'labels' => $category_labels,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'publication-category'),
            'show_in_rest' => true,
        ));
        
        // Publications can also use regular tags
        register_taxonomy_for_object_type('post_tag', 'publication');
    }
    
    /**
     * Enqueue Scripts and Styles
     */
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'publicationsAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('publications_filter_nonce')
        ));
    }
    
    /**
     * AJAX Handler for Filtered Publications
     */
    public function get_filtered_publications() {
        // Verify nonce for security
        if (!wp_verify_nonce($_POST['nonce'], 'publications_filter_nonce')) {
            wp_die('Security check failed');
        }
        
        // Get all publications
        $publications = get_posts(array(
            'post_type' => 'publication',
            'posts_per_page' => -1,
            'post_status' => 'publish'
        ));
        
        $formatted_publications = array();
        $all_categories = array();
        $all_tags = array();
        $all_authors = array();
        
        foreach ($publications as $publication) {
            setup_postdata($publication);
            
            // Get publication categories
            $categories = get_the_terms($publication->ID, 'publication_category');
            $pub_categories = array();
            $pub_category_names = array();
            if ($categories && !is_wp_error($categories)) {
                foreach ($categories as $cat) {
                    $pub_categories[] = $cat->slug;
                    $pub_category_names[] = $cat->name;
                    if (!in_array($cat->slug, array_column($all_categories, 'slug'))) {
                        $all_categories[] = array('slug' => $cat->slug, 'name' => $cat->name);
                    }
                }
            }
            
            // Get tags
            $tags = get_the_tags($publication->ID);
            $pub_tags = array();
            $pub_tag_names = array();
            if ($tags && !is_wp_error($tags)) {
                foreach ($tags as $tag) {
                    $pub_tags[] = $tag->slug;
                    $pub_tag_names[] = $tag->name;
                    if (!in_array($tag->slug, array_column($all_tags, 'slug'))) {
                        $all_tags[] = array('slug' => $tag->slug, 'name' => $tag->name);
                    }
                }
            }
            
            // Get author
            $author_id = $publication->post_author;
            $author_name = get_the_author_meta('display_name', $author_id);
            $author_slug = get_the_author_meta('user_nicename', $author_id);
            if (!in_array($author_slug, array_column($all_authors, 'slug'))) {
                $all_authors[] = array('slug' => $author_slug, 'name' => $author_name);
            }
            
            // Get featured image
            $featured_image = array(
                'url' => '',
                'alt' => '',
                'sizes' => array()
            );
            
            if (has_post_thumbnail($publication->ID)) {
                $thumbnail_id = get_post_thumbnail_id($publication->ID);
                $featured_image['url'] = get_the_post_thumbnail_url($publication->ID, 'full');
                $featured_image['alt'] = get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true);
                
                // Get different image sizes
                $image_sizes = array('thumbnail', 'medium', 'medium_large', 'large', 'full');
                foreach ($image_sizes as $size) {
                    $image_data = wp_get_attachment_image_src($thumbnail_id, $size);
                    if ($image_data) {
                        $featured_image['sizes'][$size] = array(
                            'url' => $image_data[0],
                            'width' => $image_data[1],
                            'height' => $image_data[2]
                        );
                    }
                }
            }
            
            $formatted_publications[] = array(
                'id' => $publication->ID,
                'title' => get_the_title($publication->ID),
                'excerpt' => get_the_excerpt($publication->ID),
                'link' => get_permalink($publication->ID),
                'date' => get_the_date('F j, Y', $publication->ID),
                'author' => $author_slug,
                'author_name' => $author_name,
                'categories' => $pub_categories,
                'category_names' => $pub_category_names,
                'tags' => $pub_tags,
                'tag_names' => $pub_tag_names,
                'featured_image' => $featured_image
            );
        }
        
        wp_reset_postdata();
        
        wp_send_json_success(array(
            'publications' => $formatted_publications,
            'filters' => array(
                'categories' => $all_categories,
                'tags' => $all_tags,
                'authors' => $all_authors
            )
        ));
    }
    
    /**
     * Shortcode to Display Publications Filter
     */
    public function publications_filter_shortcode($atts) {
        $atts = shortcode_atts(array(
            'show_images' => 'true',
            'posts_per_row' => '3'
        ), $atts);
        
        ob_start();
        ?>
        <div id="publications-filter-container">
            <!-- FILTER BAR -->
            <div class="publications-filter-bar">
                <span class="filter-label">Filter Publications</span>
                
                <div class="facet-dropdown categories">
                    <select id="pub-categories-filter">
                        <option value="">Categories</option>
                    </select>
                </div>
                
                <div class="facet-dropdown tags">
                    <select id="pub-tags-filter">
                        <option value="">Tags</option>
                    </select>
                </div>
                
                <div class="facet-dropdown authors">
                    <select id="pub-authors-filter">
                        <option value="">Authors</option>
                    </select>
                </div>
                
                <a href="#" class="show-all-btn" onclick="clearAllPublicationFilters(); return false;">Show all</a>
            </div>

            <!-- PUBLICATIONS CONTAINER -->
            <div class="publications-container" id="publications-container">
                <!-- This will be populated dynamically -->
            </div>
            
            <div class="no-results" id="pub-no-results" style="display: none;">
                No publications match the selected filters.
            </div>
        </div>
        
        <style>
        /* Publications Filter Styles */
        :root {
            --pub-font-family: system-ui, -apple-system, sans-serif;
            --pub-primary-color: #007cba;
            --pub-primary-hover: #0056b3;
            --pub-bg-color: #ffffff;
            --pub-text-color: #333333;
            --pub-muted-color: #666666;
            --pub-light-bg: #f8f9fa;
            --pub-border-color: #dee2e6;
            --pub-shadow-color: rgba(0,0,0,0.1);
            --pub-tag-bg: #f0f0f0;
            --pub-spacing-sm: 8px;
            --pub-spacing-md: 16px;
            --pub-spacing-lg: 24px;
            --pub-border-radius: 8px;
            --pub-border-radius-sm: 4px;
        }

        .publications-filter-bar {
            display: flex;
            align-items: center;
            gap: var(--pub-spacing-md);
            padding: var(--pub-spacing-md) var(--pub-spacing-lg);
            background: var(--pub-bg-color);
            border-radius: var(--pub-border-radius);
            box-shadow: 0 2px 8px var(--pub-shadow-color);
            flex-wrap: wrap;
            margin-bottom: var(--pub-spacing-lg);
            font-family: var(--pub-font-family);
        }
        
        .publications-filter-bar .filter-label {
            font-weight: 600;
            color: var(--pub-text-color);
            font-size: 14px;
        }
        
        .publications-filter-bar .facet-dropdown {
            position: relative;
        }
        
        .publications-filter-bar .facet-dropdown select {
            appearance: none;
            background: var(--pub-light-bg);
            border: 1px solid var(--pub-border-color);
            padding: var(--pub-spacing-sm) 32px var(--pub-spacing-sm) 32px;
            border-radius: var(--pub-border-radius-sm);
            font-size: 14px;
            color: var(--pub-text-color);
            cursor: pointer;
            min-width: 140px;
            font-family: var(--pub-font-family);
        }
        
        .publications-filter-bar .facet-dropdown select:hover {
            background: var(--pub-border-color);
        }
        
        .publications-filter-bar .facet-dropdown select:focus {
            outline: none;
            border-color: var(--pub-primary-color);
            box-shadow: 0 0 0 2px rgba(0, 124, 186, 0.1);
        }
        
        .publications-filter-bar .facet-dropdown::before {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: white;
            font-size: 12px;
            z-index: 1;
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
        }
        
        .publications-filter-bar .facet-dropdown.categories::before {
            content: '\f02b';
        }
        
        .publications-filter-bar .facet-dropdown.tags::before {
            content: '\f02c';
        }
        
        .publications-filter-bar .facet-dropdown.authors::before {
            content: '\f007';
        }
        
        .publications-filter-bar .facet-dropdown::after {
            content: '▼';
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: var(--pub-muted-color);
            font-size: 10px;
        }
        
        .publications-filter-bar .show-all-btn {
            background: none;
            border: none;
            color: var(--pub-primary-color);
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
            margin-left: auto;
            padding: var(--pub-spacing-sm) 12px;
        }
        
        .publications-filter-bar .show-all-btn:hover {
            color: var(--pub-primary-hover);
            text-decoration: underline;
        }
        
        .publications-filter-bar .show-all-btn::before {
            content: '×';
            margin-right: 4px;
            font-weight: bold;
        }
        
        .publications-filter-bar .facet-dropdown select:not([value=""]) {
            background: var(--pub-primary-color);
            color: var(--pub-bg-color);
            border-color: var(--pub-primary-color);
        }
        
        .publications-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: var(--pub-spacing-lg);
            margin-top: var(--pub-spacing-lg);
        }
        
        .publication-item {
            background: var(--pub-bg-color);
            padding: var(--pub-spacing-lg);
            border-radius: var(--pub-border-radius);
            box-shadow: 0 2px 8px var(--pub-shadow-color);
            transition: all 0.3s ease;
        }
        
        .publication-item h3 {
            margin: 0 0 12px 0;
            color: var(--pub-text-color);
            font-size: 18px;
        }
        
        .publication-item .pub-meta {
            color: var(--pub-muted-color);
            font-size: 14px;
            margin-bottom: 12px;
        }
        
        .publication-item .pub-excerpt {
            color: var(--pub-text-color);
            line-height: 1.6;
            margin-bottom: var(--pub-spacing-md);
            font-size: 16px;
        }
        
        .publication-item .pub-categories, .publication-item .pub-tags {
            display: flex;
            gap: var(--pub-spacing-sm);
            flex-wrap: wrap;
            margin-bottom: var(--pub-spacing-sm);
        }
        
        .publication-item .pub-categories span, .publication-item .pub-tags span {
            background: var(--pub-tag-bg);
            padding: 4px var(--pub-spacing-sm);
            border-radius: var(--pub-border-radius-sm);
            font-size: 12px;
            color: var(--pub-muted-color);
        }
        
        .publication-item .pub-image {
            width: 100%;
            height: 200px;
            overflow: hidden;
            margin-bottom: var(--pub-spacing-md);
            border-radius: var(--pub-border-radius-sm);
        }
        
        .publication-item .pub-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .publication-item .pub-no-image {
            background: var(--pub-light-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--pub-muted-color);
            font-style: italic;
        }
        
        .no-results {
            text-align: center;
            padding: 60px 20px;
            color: var(--pub-muted-color);
            font-size: 16px;
        }
        
        @media (max-width: 768px) {
            .publications-filter-bar {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
            }
            
            .publications-filter-bar .facet-dropdown select {
                width: 100%;
            }
            
            .publications-filter-bar .show-all-btn {
                margin-left: 0;
                align-self: flex-start;
            }
            
            .publications-container {
                grid-template-columns: 1fr;
            }
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            let allPublications = [];
            
            // Load publications and populate filters
            loadPublicationsAndFilters();
            
            function loadPublicationsAndFilters() {
                $.ajax({
                    url: publicationsAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_filtered_publications',
                        nonce: publicationsAjax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            allPublications = response.data.publications;
                            populatePublicationFilters(response.data.filters);
                            displayPublications(allPublications);
                        }
                    }
                });
            }
            
            function populatePublicationFilters(filters) {
                const categoriesSelect = $('#pub-categories-filter');
                filters.categories.forEach(cat => {
                    categoriesSelect.append(`<option value="${cat.slug}">${cat.name}</option>`);
                });
                
                const tagsSelect = $('#pub-tags-filter');
                filters.tags.forEach(tag => {
                    tagsSelect.append(`<option value="${tag.slug}">${tag.name}</option>`);
                });
                
                const authorsSelect = $('#pub-authors-filter');
                filters.authors.forEach(author => {
                    authorsSelect.append(`<option value="${author.slug}">${author.name}</option>`);
                });
            }
            
            function displayPublications(publications) {
                const container = $('#publications-container');
                const noResults = $('#pub-no-results');
                
                if (publications.length === 0) {
                    container.hide();
                    noResults.show();
                    return;
                }
                
                container.show();
                noResults.hide();
                
                container.html(publications.map(pub => {
                    let imageHtml = '';
                    if (pub.featured_image && pub.featured_image.url) {
                        const imgSrc = pub.featured_image.sizes && pub.featured_image.sizes.medium 
                            ? pub.featured_image.sizes.medium.url 
                            : pub.featured_image.url;
                        const imgAlt = pub.featured_image.alt || pub.title;
                        imageHtml = `<div class="pub-image"><img src="${imgSrc}" alt="${imgAlt}" loading="lazy"></div>`;
                    } else {
                        imageHtml = `<div class="pub-image pub-no-image">No Image</div>`;
                    }
                    
                    return `
                        <div class="publication-item">
                            ${imageHtml}
                            <h3><a href="${pub.link}">${pub.title}</a></h3>
                            <div class="pub-meta">${pub.date} | By ${pub.author_name}</div>
                            <div class="pub-excerpt">${pub.excerpt}</div>
                            <div class="pub-categories">
                                ${pub.category_names.map(cat => `<span>${cat}</span>`).join('')}
                            </div>
                            <div class="pub-tags">
                                ${pub.tag_names.map(tag => `<span>${tag}</span>`).join('')}
                            </div>
                        </div>
                    `;
                }).join(''));
            }
            
            function filterPublications() {
                const categoryFilter = $('#pub-categories-filter').val();
                const tagFilter = $('#pub-tags-filter').val();
                const authorFilter = $('#pub-authors-filter').val();
                
                const filteredPublications = allPublications.filter(pub => {
                    const matchesCategory = !categoryFilter || pub.categories.includes(categoryFilter);
                    const matchesTag = !tagFilter || pub.tags.includes(tagFilter);
                    const matchesAuthor = !authorFilter || pub.author === authorFilter;
                    
                    return matchesCategory && matchesTag && matchesAuthor;
                });
                
                displayPublications(filteredPublications);
            }
            
            $('#pub-categories-filter, #pub-tags-filter, #pub-authors-filter').on('change', filterPublications);
            
            window.clearAllPublicationFilters = function() {
                $('#pub-categories-filter, #pub-tags-filter, #pub-authors-filter').val('');
                displayPublications(allPublications);
            };
        });
        </script>
        <?php
        return ob_get_clean();
    }
}

// Initialize the plugin
new PublicationsFilterPlugin();

// Activation hook to flush rewrite rules
register_activation_hook(__FILE__, 'publications_flush_rewrite_rules');
function publications_flush_rewrite_rules() {
    // Call our plugin's init function to register post types
    $plugin = new PublicationsFilterPlugin();
    $plugin->create_publications_post_type();
    $plugin->create_publications_taxonomies();
    
    // Flush rewrite rules
    flush_rewrite_rules();
}