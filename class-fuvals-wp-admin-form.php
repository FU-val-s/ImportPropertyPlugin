<?php
// /wp-content/plugins/imporHouzezPlugin-main/class-fuvals-wp-admin-form.php
class Fuvals_Admin_Form
{
    const ID = 'fuvals-admin';
    const NONCE_KEY = 'fuvals_submit_property_update_nonce';
    private $views = ['view1' => 'tools.php', 'not-found' => 'not_found.php'];
    public function init()
    {
        add_action('admin_menu', array($this, 'add_menu_page'), 20);
        add_action('admin_post_fuvals_submit_property_update', array($this, 'submit_property_update'));
    }
    public function get_id()
    {
      return self::ID;
    }
    public function get_nonce_key()
    {
      return self::NONCE_KEY;
    }
    public function add_menu_page()
    {
        add_menu_page(
            esc_html__('Tokko sync', 'fuvals-admin'),
            esc_html__('Tokko sync', 'fuvals-admin'),
            'manage_options',
            $this->get_id(),
            array(&$this, 'load_view'),
            'dashicons-admin-page'
        );
        add_submenu_page(
            $this->get_id(),
            esc_html__('Tools', 'fuvals-admin'),
            esc_html__('Tools', 'fuvals-admin'),
            'manage_options',
            $this->get_id() . '_view1',
            array(&$this, 'load_view')
        );
    }
    function load_view()
    {
        $this->default_values = [];
        $this->current_page = 'view1';

        $current_views = isset($this->views[$this->current_page]) ? $this->views[$this->current_page] : $this->views['not-found'];
        $step_data_func_name = $this->current_page . '_data';
        $args = [];
        /**
         * prepare data for view
         */
        if (method_exists($this, $step_data_func_name)) {
            $args = $this->$step_data_func_name();
        }
        /**
         * Default Admin Form Template
         */
        echo '<div class="fuvals-admin-forms ' . $this->current_page . '">';
        echo '<div class="container container1">';
        echo '<div class="inner">';
        $this->includeWithVariables(plugin_dir_path( __FILE__ ) .'/templates/alerts.php');
        $this->includeWithVariables(plugin_dir_path( __FILE__ ) .'/templates/'.$current_views, $args);
        echo '</div>';
        echo '</div>';
        echo '</div> <!-- / fuvals-admin-forms -->';
    }
    function includeWithVariables($filePath, $variables = array(), $print = true)
    {
        $output = NULL;
        if (file_exists($filePath)) {
            // Extract the variables to a local namespace
            extract($variables);
            // Start output buffering
            ob_start();
            // Include the template file
            include $filePath;
            // End buffering and return its contents
            $output = ob_get_clean();
        }
        if ($print) {
            print $output;
        }
        return $output;
    }
    //DATA VIEW
    private function view1_data() {
        $services_args = array(
            'post_type'        => 'any',
            'numberposts'      => 1,
            'suppress_filters' => false,
        );
        $blog_posts = get_posts($services_args);
        $args = [];
        $args['posts'] = $blog_posts;
        return $args;
    }
    // SUBMIT
    public function submit_property_update() {
      $nonce = sanitize_text_field($_POST[$this->get_nonce_key()]);
      $action = sanitize_text_field($_POST['action']);
      if (!isset($nonce) || !wp_verify_nonce($nonce, $action)) {
        print 'Sorry, your nonce did not verify.';
        exit;
      }
      /*if (!current_user_can('manage_options')) {
        print 'You can\'t manage options';
        exit;
      }*/
      $property_id = $_POST['property_id'];
      error_log("\nUPDATING PROPERTY VALIDATE: $property_id");
      if ( !empty($property_id) ) {
        set_time_limit(0);
        error_log("\nUPDATING PROPERTY: $property_id" );
        $houzezImport = new Fuvals_houzezImport_Tokko(0, true);
        error_log("\nOBJECT OK: $property_id" );
        $property = $houzezImport->property_details($property_id);
        error_log("PROP DETAILS: ".print_r($property, true));
        $houzezImport->process_property(json_decode(json_encode($property), true));
        //Redirect
        error_log("\nPROPERTY UPDATing redirecting: $property_id");
      }
      wp_safe_redirect(wp_get_referer());
      exit;
    }
}
