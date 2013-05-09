<?php if ( ! defined('BASEPATH')) exit('Invalid file request');

/**
 * Effortlessly add members of your ExpressionEngine site to your MailChimp
 * mailing lists.
 *
 * @author    Stephen Lewis <addons@experienceinternet.co.uk>
 * @link      http://experienceinternet.co.uk/software/mailchimp-subscribe/
 * @package   MailChimp_subscribe
 */

class Mailchimp_subscribe_ext {

  private $_ee;

  public $description;
  public $docs;
  public $name;
  public $settings;
  public $settings_exist;
  public $version;



  /* --------------------------------------------------------------
   * PUBLIC METHODS
   * ------------------------------------------------------------ */

  /**
   * Class constructor.
   *
   * @access  public
   * @param array     $settings   Previously-saved extension settings.
   * @return  void
   */
  public function __construct($settings = array())
  {
    $this->_ee =& get_instance();

    // Load our glamorous assistants.
    $this->_ee->load->helper('form');
    $this->_ee->load->library('table');

    // Need to explicitly set the package path, annoyingly.
    $this->_ee->load->add_package_path(PATH_THIRD .'mailchimp_subscribe/');
    $this->_ee->load->model('mailchimp_model');

    $this->description = 'Effortlessly add members of your ExpressionEngine'
      .' site to your MailChimp mailing lists.';

    $this->docs_url       = 'http://experienceinternet.co.uk/software/mailchimp-subscribe/';
    $this->name           = 'MailChimp Subscribe';
    $this->settings       = array();
    $this->settings_exist = 'y';
    $this->version        = $this->_ee->mailchimp_model->get_version();
  }


  /**
   * Activates the extension.
   *
   * @access  public
   * @return  void
   */
  public function activate_extension()
  {
    $this->_ee->mailchimp_model->activate_extension();
  }


  /**
   * Disables the extension.
   *
   * @access  public
   * @return  void
   */
  public function disable_extension()
  {
    $this->_ee->mailchimp_model->disable_extension();
  }


  /**
   * Saves the extension settings.
   *
   * @access  public
   * @return  void
   */
  public function save_settings()
  {
    // Need to explicitly load the language file.
    $this->_ee->lang->loadfile('mailchimp_subscribe');

    // Update the settings with any input data.
    $this->_ee->mailchimp_model->update_settings_from_input();

    // Save the settings.
    if ($this->_ee->mailchimp_model->save_settings())
    {
      $this->_ee->session->set_flashdata('message_success',
        $this->_ee->lang->line('settings_saved'));
    }
    else
    {
      $this->_ee->session->set_flashdata('message_failure',
        $this->_ee->lang->line('settings_not_saved'));
    }
  }


  /**
   * Displays the extension settings form.
   *
   * @access  public
   * @return  string
   */
  public function settings_form()
  {
    // Define the navigation.
    $base_url = BASE .AMP .'C=addons_extensions' .AMP .'M=extension_settings'
      .AMP .'file=mailchimp_subscribe' .AMP .'tab=';

    $this->_ee->cp->set_right_nav(array(
      'nav_settings'    => $base_url .'settings',
      'nav_unsubscribe' => $base_url .'unsubscribe_urls'
    ));

    switch ($this->_ee->input->get('tab'))
    {
      case 'unsubscribe_urls':
        return $this->_display_unsubscribe_urls();
        break;

      default:
        return $this->_display_settings_form();
        break;
    }
  }


  /**
   * Updates the extension.
   *
   * @access  public
   * @param string    $current_version  The current version.
   * @return  bool
   */
  public function update_extension($current_version = '')
  {
    return $this->_ee->mailchimp_model->update_extension($current_version);
  }

  /* --------------------------------------------------------------
   * PROFILE:EDIT HOOK HANDLERS
   * ------------------------------------------------------------ */

  /**
   * Handles the Profile:Edit profile_register_end hook.
   *
   * @author  Surprise Highway <http://github.com/surprisehighway>
   * @since   2.1.0
   * @access  public
   * @param   int       $member_id      The member ID.
   * @param   array     $member_data    An array of member data.
   */

  public function profile_register_end($member_id, Array $member_data)
  {
    $this->_ee->mailchimp_model->subscribe_member($member_id);
    return $member_data;
  }

  /**
   * Handles the Profile:Edit profile_edit_end hook.
   *
   * @author  Surprise Highway <http://github.com/surprisehighway>
   * @since   2.1.0
   * @access  public
   * @param   int       $member_id      The member ID.
   * @param   array     $member_data    An array of member data.
   */

  public function profile_edit_end($member_id, Array $member_data)
  {
    $this->_ee->mailchimp_model->subscribe_member($member_id);
    return $member_data;
  }

  /* --------------------------------------------------------------
   * PRIVATE METHODS
   * ------------------------------------------------------------ */

  /**
   * Displays the settings form.
   *
   * @access  private
   * @return  string
   */
  private function _display_settings_form()
  {
    // Load the member fields.
    $member_fields = $this->_ee->mailchimp_model->get_member_fields();

    $cleaned_member_fields = array(
      '' => $this->_ee->lang->line('trigger_field_hint'));

    foreach ($member_fields AS $key => $data)
    {
      $cleaned_member_fields[$key] = $data['label'];
    }

    // Collate the view variables.
    $vars = array(
      'action_url' => 'C=addons_extensions' .AMP .'M=save_extension_settings',
      'cleaned_member_fields' => $cleaned_member_fields,
      'cp_page_title' => $this->_ee->lang->line('extension_name'),
      'hidden_fields' => array('file' => strtolower(
        substr(get_class($this), 0, -4))),

      'member_fields' => $member_fields,
      'view_settings' => $this->_ee->mailchimp_model->get_view_settings()
    );

    // Is this an AJAX request?
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
      && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'
    )
    {
      // Update the settings with any input data.
      $this->_ee->mailchimp_model->update_settings_from_input();

      // Update the view settings.
      $vars['view_settings'] = $this->_ee->mailchimp_model->get_view_settings();

      $output = $this->_ee->load->view('_mailing_lists', $vars, TRUE);
      $this->_ee->output->send_ajax_response($output);
    }
    else
    {
      // Retrieve the theme folder URL.
      $theme_url = $this->_ee->mailchimp_model->get_theme_url();

      // Include the JavaScript.
      $this->_ee->load->library('javascript');

      // Set the global variables.
      $this->_ee->javascript->set_global('mailChimp.lang', array(
        'missingApiKey' => $this->_ee->lang->line('missing_api_key')
      ));

      $this->_ee->javascript->set_global('mailChimp.memberFields',
        $this->_ee->javascript->generate_json($member_fields));

      $this->_ee->javascript->set_global('mailChimp.globals.ajaxUrl',
        str_replace(AMP, '&', BASE)
        .'&C=addons_extensions&M=extension_settings&file=mailchimp_subscribe');

      // Include the main JS file.
      $this->_ee->cp->add_to_foot('<script type="text/javascript" src="'
        .$theme_url .'js/cp.js"></script>');

      $this->_ee->javascript->compile();

      // Include the CSS.
      $this->_ee->cp->add_to_foot('<link media="screen, projection" rel="stylesheet" type="text/css" href="' .$theme_url .'css/cp.css" />');

      // Load the view.
      return $this->_ee->load->view('settings', $vars, TRUE);
    }
  }


  /**
   * Displays the unsubscribe URLs.
   *
   * @access  private
   * @return  string
   */
  private function _display_unsubscribe_urls()
  {
    // Collate the view variables.
    $vars = array(
      'cp_page_title' => $this->_ee->lang->line('extension_name'),
      'view_settings' => $this->_ee->mailchimp_model->get_view_settings()
    );

    // Load the view.
    return $this->_ee->load->view('unsubscribe_urls', $vars, TRUE);
  }


}


/* End of file    : ext.mailchimp_subscribe.php */
/* File location  : third_party/mailchimp_subscribe/ext.mailchimp_subscribe.php */
