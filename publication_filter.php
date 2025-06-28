<?php
/**
 * Plugin Name: Publications Filter System
 * Description: Custom post type for publications with advanced filtering by categories, tags, and multiple authors
 * Version: 1.0.0
 * Author: Lecha
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PublicationsFilterPlugin {
    
    public function __construct() {
        add_action('init', array($this, 'create_publication_post_type'));
        add_action('init', array($this, 'create_publication_taxonomy'));
        add_action('add_meta_boxes', array($this, 'add_authors_meta_box'));
        add_action('save_post', array($this, 'save_authors_meta_box'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_get_filtered_publications', array($this, 'get_filtered_publications'));
        add_action('wp_ajax_nopriv_get_filtered_publications', array($this, 'get_filtered_publications'));
        add_shortcode('publications_filter', array($this, 'display_publications_filter'));
        
        // Add custom columns to admin
        add_filter('manage_publication_posts_columns', array($this, 'add_publication_columns'));
        add_action('manage_publication_posts_custom_column', array($this, 'publication_column_content'), 10, 2);
    }
    
    /**
     * Create custom post type 'publication'
     */
    public function create_publication_post_type() {
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
            'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'comments'),
            'taxonomies' => array('publication_category', 'post_tag') // Use existing tags + custom categories
        );
        
        register_post_type('publication', $args);
    }
    
    /**
     * Create custom taxonomy for publication categories
     */
    public function create_publication_taxonomy() {
        $labels = array(
            'name' => 'Publication Categories',
            'singular_name' => 'Publication Category',
            'search_items' => 'Search Publication Categories',
            'all_items' => 'All Publication Categories',
            'parent_item' => 'Parent Publication Category',
            'parent_item_colon' => 'Parent Publication Category:',
            'edit_item' => 'Edit Publication Category',
            'update_item' => 'Update Publication Category',
            'add_new_item' => 'Add New Publication Category',
            'new_item_name' => 'New Publication Category Name',
            'menu_name' => 'Categories',
        );
        
        $args = array(
            'hierarchical' => true,
            'labels' => $labels,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'publication-category'),
        );
        
        register_taxonomy('publication_category', array('publication'), $args);
    }
    
    /**
     * Add meta box for multiple authors
     */
    public function add_authors_meta_box() {
        add_meta_box(
            'publication_authors',
            'Publication Authors',
            array($this, 'authors_meta_box_callback'),
            'publication',
            'normal',
            'high'
        );
    }
    
    /**
     * Authors meta box callback
     */
    public function authors_meta_box_callback($post) {
        wp_nonce_field('publication_authors_nonce', 'publication_authors_nonce');
        
        $selected_authors = get_post_meta($post->ID, '_publication_authors', true);
        if (!is_array($selected_authors)) {
            $selected_authors = array();
        }
        
        $users = get_users(array('orderby' => 'display_name'));
        
        echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">';
        echo '<p><strong>Select one or more authors for this publication:</strong></p>';
        
        foreach ($users as $user) {
            $checked = in_array($user->ID, $selected_authors) ? 'checked' : '';
            echo '<label style="display: block; margin-bottom: 5px;">';
            echo '<input type="checkbox" name="publication_authors[]" value="' . $user->ID . '" ' . $checked . '> ';
            echo $user->display_name . ' (' . $user->user_login . ')';
            echo '</label>';
        }
        
        echo '</div>';
        echo '<p><em>You can select multiple authors. They will all be displayed in the publication meta.</em></p>';
    }
    
    /**
     * Save authors meta box
     */
    public function save_authors_meta_box($post_id) {
        if (!isset($_POST['publication_authors_nonce']) || !wp_verify_nonce($_POST['publication_authors_nonce'], 'publication_authors_nonce')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (isset($_POST['publication_authors'])) {
            $authors = array_map('intval', $_POST['publication_authors']);
            update_post_meta($post_id, '_publication_authors', $authors);
        } else {
            delete_post_meta($post_id, '_publication_authors');
        }
    }
    
    /**
     * Add custom columns to publications admin list
     */
    public function add_publication_columns($columns) {
        $columns['publication_authors'] = 'Authors';
        $columns['publication_category'] = 'Categories';
        return $columns;
    }
    
    /**
     * Display content for custom columns
     */
    public function publication_column_content($column, $post_id) {
        switch ($column) {
            case 'publication_authors':
                $authors = get_post_meta($post_id, '_publication_authors', true);
                if (is_array($authors) && !empty($authors)) {
                    $author_names = array();
                    foreach ($authors as $author_id) {
                        $user = get_userdata($author_id);
                        if ($user) {
                            $author_names[] = $user->display_name;
                        }
                    }
                    echo implode(', ', $author_names);
                } else {
                    echo 'No authors assigned';
                }
                break;
                
            case 'publication_category':
                $terms = get_the_terms($post_id, 'publication_category');
                if ($terms && !is_wp_error($terms)) {
                    $term_names = array();
                    foreach ($terms as $term) {
                        $term_names[] = $term->name;
                    }
                    echo implode(', ', $term_names);
                } else {
                    echo 'No categories';
                }
                break;
        }
    }
    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'publicationsAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('publications_filter_nonce')
        ));
    }
    
    /**
     * AJAX handler for filtered publications
     */
    public function get_filtered_publications() {
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
            
            // Get tags (using WordPress built-in tags)
            $tags = get_the_tags($publication->ID);
            $pub_tags = array();
            $pub_tag_names = array();
            if ($tags) {
                foreach ($tags as $tag) {
                    $pub_tags[] = $tag->slug;
                    $pub_tag_names[] = $tag->name;
                    if (!in_array($tag->slug, array_column($all_tags, 'slug'))) {
                        $all_tags[] = array('slug' => $tag->slug, 'name' => $tag->name);
                    }
                }
            }
            
            // Get publication authors (multiple)
            $author_ids = get_post_meta($publication->ID, '_publication_authors', true);
            $pub_authors = array();
            $pub_author_names = array();
            $pub_author_slugs = array();
            
            if (is_array($author_ids) && !empty($author_ids)) {
                foreach ($author_ids as $author_id) {
                    $user = get_userdata($author_id);
                    if ($user) {
                        $author_slug = $user->user_nicename;
                        $pub_authors[] = $author_id;
                        $pub_author_names[] = $user->display_name;
                        $pub_author_slugs[] = $author_slug;
                        
                        if (!in_array($author_id, array_column($all_authors, 'id'))) {
                            $all_authors[] = array(
                                'id' => $author_id,
                                'slug' => $author_slug, 
                                'name' => $user->display_name
                            );
                        }
                    }
                }
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
                'authors' => $pub_authors,
                'author_names' => $pub_author_names,
                'author_slugs' => $pub_author_slugs,
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
     * Shortcode to display publications filter
     */
    public function display_publications_filter($atts) {
        $atts = shortcode_atts(array(
            'show_images' => 'true'
        ), $atts);
        
        ob_start();
        ?>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            .publications-filter-container {
                font-family: system-ui, -apple-system, sans-serif;
            }
            
            .filter-bar {
                display: flex;
                align-items: center;
                gap: 16px;
                padding: 16px 24px;
                background: #ffffff;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                flex-wrap: wrap;
                margin-bottom: 24px;
            }
            
            .filter-label {
                font-weight: 600;
                color: #333333;
                font-size: 14px;
            }
            
            .facet-dropdown {
                position: relative;
            }
            
            .facet-dropdown select {
                appearance: none;
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                padding: 8px 32px 8px 32px;
                border-radius: 4px;
                font-size: 14px;
                color: #333333;
                cursor: pointer;
                min-width: 140px;
            }
            
            .facet-dropdown select:hover {
                background: #dee2e6;
            }
            
            .facet-dropdown select:focus {
                outline: none;
                border-color: #007cba;
                box-shadow: 0 0 0 2px rgba(0, 124, 186, 0.1);
            }
            
            .facet-dropdown::before {
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
            
            .facet-dropdown.categories::before {
                content: '\f02b';
            }
            
            .facet-dropdown.tags::before {
                content: '\f02c';
            }
            
            .facet-dropdown.authors::before {
                content: '\f007';
            }
            
            .facet-dropdown::after {
                content: '▼';
                position: absolute;
                right: 12px;
                top: 50%;
                transform: translateY(-50%);
                pointer-events: none;
                color: #666666;
                font-size: 10px;
            }
            
            .show-all-btn {
                background: none;
                border: none;
                color: #007cba;
                font-size: 14px;
                cursor: pointer;
                text-decoration: none;
                margin-left: auto;
                padding: 8px 12px;
            }
            
            .show-all-btn:hover {
                color: #0056b3;
                text-decoration: underline;
            }
            
            .show-all-btn::before {
                content: '×';
                margin-right: 4px;
                font-weight: bold;
            }
            
            .facet-dropdown select:not([value=""]) {
                background: #007cba;
                color: #ffffff;
                border-color: #007cba;
            }
            
            .publications-container {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 24px;
                margin-top: 24px;
            }
            
            .publication-item {
                background: #ffffff;
                padding: 24px;
                border-radius: 8px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                transition: all 0.3s ease;
            }
            
            .publication-item.hidden {
                display: none;
            }
            
            .publication-item h3 {
                margin: 0 0 12px 0;
                color: #333333;
                font-size: 18px;
            }
            
            .publication-item h3 a {
                text-decoration: none;
                color: inherit;
            }
            
            .publication-item h3 a:hover {
                color: #007cba;
            }
            
            .publication-meta {
                color: #666666;
                font-size: 14px;
                margin-bottom: 12px;
            }
            
            .publication-excerpt {
                color: #333333;
                line-height: 1.6;
                margin-bottom: 16px;
                font-size: 16px;
            }
            
            .publication-categories, .publication-tags {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
                margin-bottom: 8px;
            }
            
            .publication-categories span, .publication-tags span {
                background: #f0f0f0;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 12px;
                color: #666666;
            }
            
            .publication-image {
                width: 100%;
                height: 200px;
                overflow: hidden;
                margin-bottom: 16px;
                border-radius: 4px;
            }
            
            .publication-image img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            
            .publication-no-image {
                background: #f8f9fa;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #6c757d;
                font-style: italic;
            }
            
            .no-results {
                text-align: center;
                padding: 60px 20px;
                color: #666666;
                font-size: 16px;
            }
            
            @media (max-width: 768px) {
                .filter-bar {
                    flex-direction: column;
                    align-items: stretch;
                    gap: 12px;
                }
                
                .facet-dropdown select {
                    width: 100%;
                }
                
                .show-all-btn {
                    margin-left: 0;
                    align-self: flex-start;
                }
                
                .publications-container {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        
        <div class="publications-filter-container">
            <div class="filter-bar">
                <span class="filter-label">Filter by</span>
                
                <div class="facet-dropdown categories">
                    <select id="categories-filter">
                        <option value="">Categories</option>
                    </select>
                </div>
                
                <div class="facet-dropdown tags">
                    <select id="tags-filter">
                        <option value="">Tags</option>
                    </select>
                </div>
                
                <div class="facet-dropdown authors">
                    <select id="authors-filter">
                        <option value="">Authors</option>
                    </select>
                </div>
                
                <a href="#" class="show-all-btn" onclick="clearAllPublicationFilters(); return false;">Show all</a>
            </div>

            <div class="publications-container" id="publications-container">
                <!-- This will be populated by AJAX -->
            </div>
            
            <div class="no-results" id="no-results" style="display: none;">
                No publications match the selected filters.
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                let allPublications = [];
                
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
                                populateFilters(response.data.filters);
                                displayPublications(allPublications);
                            }
                        }
                    });
                }
                
                function populateFilters(filters) {
                    const categoriesSelect = $('#categories-filter');
                    filters.categories.forEach(cat => {
                        categoriesSelect.append(`<option value="${cat.slug}">${cat.name}</option>`);
                    });
                    
                    const tagsSelect = $('#tags-filter');
                    filters.tags.forEach(tag => {
                        tagsSelect.append(`<option value="${tag.slug}">${tag.name}</option>`);
                    });
                    
                    const authorsSelect = $('#authors-filter');
                    filters.authors.forEach(author => {
                        authorsSelect.append(`<option value="${author.id}">${author.name}</option>`);
                    });
                }
                
                function displayPublications(publications) {
                    const container = $('#publications-container');
                    const noResults = $('#no-results');
                    
                    if (publications.length === 0) {
                        container.hide();
                        noResults.show();
                        return;
                    }
                    
                    container.show();
                    noResults.hide();
                    
                    container.html(publications.map(pub => {
                        let imageHtml = '';
                        <?php if ($atts['show_images'] === 'true'): ?>
                        if (pub.featured_image && pub.featured_image.url) {
                            const imgSrc = pub.featured_image.sizes && pub.featured_image.sizes.medium 
                                ? pub.featured_image.sizes.medium.url 
                                : pub.featured_image.url;
                            const imgAlt = pub.featured_image.alt || pub.title;
                            imageHtml = `<div class="publication-image"><img src="${imgSrc}" alt="${imgAlt}" loading="lazy"></div>`;
                        } else {
                            imageHtml = `<div class="publication-image publication-no-image">No Image</div>`;
                        }
                        <?php endif; ?>
                        
                        return `
                            <div class="publication-item" data-categories="${pub.categories.join(',')}" data-tags="${pub.tags.join(',')}" data-authors="${pub.authors.join(',')}">
                                ${imageHtml}
                                <h3><a href="${pub.link}">${pub.title}</a></h3>
                                <div class="publication-meta">${pub.date} | By ${pub.author_names.join(', ')}</div>
                                <div class="publication-excerpt">${pub.excerpt}</div>
                                <div class="publication-categories">
                                    ${pub.category_names.map(cat => `<span>${cat}</span>`).join('')}
                                </div>
                                <div class="publication-tags">
                                    ${pub.tag_names.map(tag => `<span>${tag}</span>`).join('')}
                                </div>
                            </div>
                        `;
                    }).join(''));
                }
                
                function filterPublications() {
                    const categoryFilter = $('#categories-filter').val();
                    const tagFilter = $('#tags-filter').val();
                    const authorFilter = $('#authors-filter').val();
                    
                    const filteredPublications = allPublications.filter(pub => {
                        const matchesCategory = !categoryFilter || pub.categories.includes(categoryFilter);
                        const matchesTag = !tagFilter || pub.tags.includes(tagFilter);
                        const matchesAuthor = !authorFilter || pub.authors.includes(parseInt(authorFilter));
                        
                        return matchesCategory && matchesTag && matchesAuthor;
                    });
                    
                    displayPublications(filteredPublications);
                }
                
                $('#categories-filter, #tags-filter, #authors-filter').on('change', filterPublications);
                
                window.clearAllPublicationFilters = function() {
                    $('#categories-filter, #tags-filter, #authors-filter').val('');
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

// Flush rewrite rules on activation
register_activation_hook(__FILE__, 'flush_rewrite_rules');
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
?>