<?php if ( ! defined('BASEPATH')) exit('Invalid file request');

/**
 * Handles all the API and database communication for MailChimp Subscribe.
 *
 * @author    Stephen Lewis <addons@experienceinternet.co.uk>
 * @link      http://experienceinternet.co.uk/software/mailchimp-subscribe/
 * @package   MailChimp Subscribe
 * @version   2.1.0
 */

require_once PATH_THIRD .'mailchimp_subscribe/library/MCAPI.class.php';
require_once PATH_THIRD .'mailchimp_subscribe/library/MCS_Base.php';
require_once PATH_THIRD .'mailchimp_subscribe/library/MCS_Api_account.php';
require_once PATH_THIRD .'mailchimp_subscribe/library/MCS_Exceptions.php';
require_once PATH_THIRD .'mailchimp_subscribe/library/MCS_Interest_group.php';
require_once PATH_THIRD .'mailchimp_subscribe/library/MCS_Mailing_list.php';
require_once PATH_THIRD .'mailchimp_subscribe/library/MCS_Merge_variable.php';
require_once PATH_THIRD .'mailchimp_subscribe/library/MCS_Settings.php';

class Mailchimp_model extends CI_Model {

  /* --------------------------------------------------------------
   * PRIVATE PROPERTIES
   * ------------------------------------------------------------ */

  private $_api_account     = NULL;
  private $_connector       = NULL;
  private $_ee;
  private $_extension_class = '';
  private $_version         = '';
  private $_mailing_lists   = array();
  private $_member_fields   = array();
  private $_settings        = NULL;
  private $_site_id         = '1';
  private $_theme_folder_url = '';
  private $_view_settings   = NULL;

  /**
   * Profile:Edit installed?
   *
   * @author  Surprise Highway <http://github.com/surprisehighway>
   * @since   2.1.0
   * @access  private
   * @var     boolean
   */
  private $_profile_edit_installed = FALSE;

  /**
   * Profile:Edit fields
   *
   * @author  Surprise Highway <http://github.com/surprisehighway>
   * @since   2.1.0
   * @access  private
   * @var     array
   */
  private $_profile_edit_member_fields = array();

  /**
   * Profile:Edit Settings
   *
   * @author  Surprise Highway <http://github.com/surprisehighway>
   * @since   2.1.0
   * @access  private
   * @var     array
   */
  private $_profile_edit_settings = array();

  /**
   * Profile:Edit Categories
   *
   * @author  Surprise Highway <http://github.com/surprisehighway>
   * @since   2.1.0
   * @access  private
   * @var     array
   */
  private $_profile_edit_member_categories = array();



  /* --------------------------------------------------------------
   * PUBLIC METHODS
   * ------------------------------------------------------------ */

  /**
   * Class constructor.
   *
   * @access  public
   * @return  void
   */
  public function __construct()
  {
    parent::__construct();


    $this->_extension_class = 'Mailchimp_subscribe_ext';
    $this->_version         = '2.1.0';
    $this->_ee              =& get_instance();
    $this->_site_id         = $this->_ee->config->item('site_id');

    /**
     * Annoyingly, this method is still called, even if the extension
     * isn't installed. We need to check if such nonsense is afoot,
     * and exit promptly if so.
     */

    if ( ! isset($this->_ee->extensions->version_numbers[
      $this->_extension_class])
    )
    {
      return;
    }

    $this->_load_settings_from_db();
    $this->_profile_edit_installed = $this->_init_profile_edit();
    $this->_load_member_fields_from_db();

    // OmniLog FTW!
    if (file_exists(PATH_THIRD .'omnilog/classes/omnilogger.php'))
    {
      include_once PATH_THIRD .'omnilog/classes/omnilogger.php';
    }
  }


  /**
   * Activates the extension.
   *
   * @access  public
   * @return  void
   */
  public function activate_extension()
  {
    $hooks = array(
      array(
        'hook'    => 'profile_register_end',
        'method'  => 'profile_register_end',
        'priority'  => 1000
      ),
      array(
        'hook'    => 'profile_edit_end',
        'method'  => 'profile_edit_end',
        'priority'  => 1000
      )
    );

    $this->_register_hooks($hooks);

    // Create the settings table.
    $fields = array(
      'site_id' => array(
        'constraint'  => 8,
        'null'        => FALSE,
        'type'        => 'int',
        'unsigned'    => TRUE
      ),
      'settings' => array(
        'null'  => FALSE,
        'type'  => 'text',
      )
    );

    $this->load->dbforge();
    $this->_ee->dbforge->add_field($fields);
    $this->_ee->dbforge->add_key('site_id', TRUE);
    $this->_ee->dbforge->create_table('mailchimp_subscribe_settings', TRUE);
  }


  /**
   * Disables the extension.
   *
   * @access  public
   * @return  void
   */
  public function disable_extension()
  {
    $this->_ee->db->delete('extensions',
      array('class' => $this->_extension_class));

    $this->_ee->load->dbforge();
    $this->_ee->dbforge->drop_table('mailchimp_subscribe_settings');
  }


  /**
   * Returns the API account details.
   *
   * @access  public
   * @return  array
   */
  public function get_api_account()
  {
    if ( ! $this->_api_account)
    {
      try
      {
        $this->_load_account_from_api();
      }
      catch (MCS_exception $exception)
      {
        $this->log_error(
          "{$exception->getMessage()} (error code: {$exception->getCode()}", 3);
      }
    }

    return $this->_api_account;
  }


  /**
   * Retrieves the available mailing lists from the API.
   *
   * @access  public
   * @return  array
   */
  public function get_mailing_lists()
  {
    if ( ! $this->_mailing_lists)
    {
      try
      {
        $this->_load_mailing_lists_from_api();
      }
      catch (MCS_exception $exception)
      {
        $this->log_error(
          "{$exception->getMessage()} (error code: {$exception->getCode()}", 3);
      }
    }

    return $this->_mailing_lists;
  }


  /**
   * Returns matching members. Pretty rudimentary at present; simply retrieves
   * all the exp_members and exp_member_data fields. Criteria are assumed to
   * refer to the exp_members table.
   *
   * @access  public
   * @param   array   $criteria   Associative array of criteria.
   * @return  array
   */
  public function get_members(Array $criteria = array())
  {

    foreach ($criteria AS $key => $val)
    {
      is_array($val)
      ? $this->_ee->db->where_in('members.' .$key, $val)
      : $this->_ee->db->where('members.' .$key, $val);
    }
    
    /**
     * If Profile:Edit is installed, get fields of the channel
     *
     * @author  Pierre-Vincent Ledoux <addons@pvledoux.be>
     * @since   2.1.0
     */

    if ($this->_profile_edit_installed !== TRUE)
    {
      return FALSE;
    }
      
      $member_channel_fields = $this->_profile_edit_member_fields;
      $channel_id = $this->_profile_edit_settings['channel_id'];

      $field_ids = array();

      foreach($member_channel_fields as $channel_field)
      {
        $field_ids[] = 'exp_channel_data.field_id_' .$channel_field->field_id;
      }

      // Generate a list of field_id
      $fields = implode(',', $field_ids);
      $this->_ee->db->select('members.*, exp_channel_titles.entry_id, '.$fields);
      $this->_ee->db->join('channel_titles',
        'exp_members.member_id = exp_channel_titles.author_id', 'inner');

      $this->_ee->db->join('exp_channel_data',
        'exp_channel_titles.entry_id = exp_channel_data.entry_id', 'inner');

      $this->_ee->db->where('exp_channel_titles.channel_id', $channel_id);

      $db_members = $this->_ee->db->get('members')->result_array();

      foreach ($db_members as $key => $member)
      {
        // Get Member categories
        $cats = $this->_ee->db->select('exp_categories.cat_id, exp_categories.group_id, exp_categories.cat_name, exp_categories.cat_url_title')
                              ->from('exp_channels')
                              ->join('exp_categories', 'exp_channels.cat_group = exp_categories.group_id')
                              ->join('exp_category_posts', 'exp_categories.cat_id = exp_category_posts.cat_id')
                              ->where('exp_channels.channel_id', $channel_id)
                              ->where('exp_category_posts.entry_id', $member['entry_id'])
                              ->get();

        if ($cats->num_rows() > 0)
        {
          $str_cats = '';
          foreach ($cats->result_array() as $cat)
          {
            // Escape cat name if it contains comma, then contact... with a comma...
            $str_cats .= str_replace(',', '\,', $cat['cat_url_title']) . ',';
          }
          $db_members[$key]['cat_group_id_'.$cat['group_id']] = substr($str_cats, 0, -1); // remove last comma
        }
      }

    return $db_members;
  }


  /**
   * Returns the available member fields.
   *
   * @access  public
   * @return  array
   */
  public function get_member_fields()
  {
    return $this->_member_fields;
  }


  /**
   * Returns the site settings.
   *
   * @access  public
   * @return  array
   */
  public function get_settings()
  {
    return $this->_settings;
  }


  /**
   * Returns the `theme` folder URL.
   *
   * @access  public
   * @return  string
   */
  public function get_theme_url()
  {
    if ( ! $this->_theme_folder_url)
    {
      $this->_theme_folder_url = $this->_ee->config->item('theme_folder_url');
      $this->_theme_folder_url .= substr($this->_theme_folder_url, -1) == '/'
        ? 'third_party/mailchimp_subscribe/'
        : '/third_party/mailchimp_subscribe/';
    }

    return $this->_theme_folder_url;
  }


  /**
   * Returns the extension version.
   *
   * @access  public
   * @return  string
   */
  public function get_version()
  {
    return $this->_version;
  }


  /**
   * Returns the "view" settings. That is, the saved settings, plus
   * any additional mailing lists, all wrapped up in a neat little
   * MCS_Settings object, for use in the view.
   *
   * @access  public
   * @return  void
   */
  public function get_view_settings()
  {
    // No point hanging around if there's no API key.
    if ( ! $this->_settings->api_key)
    {
      $this->_view_settings = new MCS_Settings();
      return $this->_view_settings;
    }

    if ( ! $this->_view_settings)
    {
      // Base unsubscribe URL.
      $unsubscribe_url = 'http://list-manage.com/unsubscribe?u=%s&amp;id=%s';

      $saved_settings = $this->get_settings();
      $mailing_lists  = $this->get_mailing_lists();
      $api_account  = $this->get_api_account();

      // Basic view settings.
      $view_settings = new MCS_Settings($saved_settings->to_array());
      $view_settings->reset_mailing_lists();

      // Loop through the mailing lists.
      foreach ($mailing_lists AS $mailing_list)
      {
        $old_list = $saved_settings->get_mailing_list($mailing_list->id)
          ? $saved_settings->get_mailing_list($mailing_list->id)
          : new MCS_Mailing_list();

        // Create the new mailing list.
        $new_list = new MCS_Mailing_list(array(
          'active'          => $old_list->active,
          'id'              => $mailing_list->id,
          'name'            => $mailing_list->name,
          'trigger_field'   => $old_list->trigger_field,
          'trigger_value'   => $old_list->trigger_value,
          'unsubscribe_url' => $api_account->user_id
            ? sprintf($unsubscribe_url, $api_account->user_id,
                $mailing_list->id)
            : ''
        ));

        // Interest Groups.
        foreach ($mailing_list->interest_groups AS $key => $val)
        {
          $temp_group = $old_list->get_interest_group($key)
            ? $old_list->get_interest_group($key)
            : new MCS_Interest_group();

          $temp_group->id  = $val->id;
          $temp_group->name   = $val->name;

          $new_list->add_interest_group($temp_group);
        }

        // Merge Variables.
        foreach ($mailing_list->merge_variables AS $key => $val)
        {
          $temp_var = $old_list->get_merge_variable($key)
            ? $old_list->get_merge_variable($key)
            : new MCS_Merge_variable();

          $temp_var->tag  = $val->tag;
          $temp_var->name = $val->name;

          $new_list->add_merge_variable($temp_var);
        }

        // Update the saved settings.
        $view_settings->add_mailing_list($new_list);
      }

      $this->_view_settings = $view_settings;
    }

    return $this->_view_settings;
  }


  /**
   * Logs an error to the database.
   *
   * @access  public
   * @param   string    $message        The log entry message.
   * @param   int       $severity       The log entry 'level.'
   * @param   array     $emails         Array of 'admin' emails.
   * @param   string    $extended_data  Additional data.
   * @return  void
   */
  public function log_error($message, $severity = 1, Array $emails = array(),
    $extended_data = ''
  )
  {
    if (class_exists('Omnilog_entry') && class_exists('Omnilogger'))
    {
      switch ($severity)
      {
        case 3:
          $notify = TRUE;
          $type   = Omnilog_entry::ERROR;
          break;

        case 2:
          $notify = FALSE;
          $type   = Omnilog_entry::WARNING;
          break;

        case 1:
        default:
          $notify = FALSE;
          $type   = Omnilog_entry::NOTICE;
          break;
      }

      $omnilog_entry = new Omnilog_entry(array(
        'addon_name'    => 'MailChimp Subscribe',
        'admin_emails'  => $emails,
        'date'          => time(),
        'extended_data' => $extended_data,
        'message'       => $message,
        'notify_admin'  => $notify,
        'type'          => $type
      ));

      Omnilogger::log($omnilog_entry);
    }
  }


  /**
   * Saves the site settings.
   *
   * @access  public
   * @return  bool
   */
  public function save_settings()
  {
    $settings = addslashes(serialize($this->_settings->to_array()));

    $this->_ee->db->delete('mailchimp_subscribe_settings', array('site_id' => $this->_site_id));
    $this->_ee->db->insert('mailchimp_subscribe_settings', array('site_id' => $this->_site_id, 'settings' => $settings));

    return TRUE;
  }


  /**
   * Subscribes a member to the active mailing list(s).
   *
   * @access  public
   * @param   string    $member_id    The member ID.
   * @return  void
   */
  public function subscribe_member($member_id)
  {
    try
    {
      $this->_update_member_subscriptions($member_id, FALSE);
    }
    catch (MCS_Exception $exception)
    {
      $this->log_error(
        "{$exception->getMessage()} (error code: {$exception->getCode()}", 3);
    }
  }


  /**
   * Updates the extension.
   *
   * @access  public
   * @param   string    $current_version    The current version.
   * @return  bool
   */
  public function update_extension($current_version = '')
  {
    if ( ! $current_version
      OR version_compare($current_version, $this->_version, '>=')
    )
    {
      return FALSE;
    }

    // Update the version number.
    $this->_ee->db->update('extensions', array('version' => $this->_version),
      array('class' => $this->_extension_class));

    return TRUE;
  }


  /**
   * Updates a member's mailing list subscriptions.
   *
   * @access  public
   * @param   string    $member_id    The member ID.
   * @return  void
   */
  public function update_member_subscriptions($member_id = '')
  {
    try
    {
      $this->_update_member_subscriptions($member_id, TRUE);
    }
    catch (MCS_Exception $exception)
    {
      $this->log_error(
        "{$exception->getMessage()} (error code: {$exception->getCode()}", 3);
    }
  }


  /**
   * Updates the settings from the input.
   *
   * @access  public
   * @param   array    $settings     The settings to update.
   * @return  array
   */
  public function update_settings_from_input()
  {
    $settings = $this->_settings;

    // Update the API key. This is the easy bit.
    $settings->api_key = ($this->_ee->input->get_post('api_key') !== FALSE)
      ? $this->_ee->input->get_post('api_key')
      : $settings->api_key;

    // The mailing lists require rather more work.
    if (is_array($lists = $this->_ee->input->get_post('mailing_lists')))
    {
      $settings->mailing_lists = array();

      foreach ($lists AS $list_id => $list_settings)
      {
        if ( ! isset($list_settings['checked']))
        {
          continue;
        }

        // Basic list information.
        $list         = new MCS_Mailing_list();
        $list->active = 'y';
        $list->id     = $list_id;

        $list->trigger_field = isset($list_settings['trigger_field'])
          ? $list_settings['trigger_field'] : '';

        $list->trigger_value = isset($list_settings['trigger_value'])
          ? $list_settings['trigger_value'] : '';

        // Interest groups.
        if (isset($list_settings['interest_groups'])
          && is_array($list_settings['interest_groups'])
        )
        {
          foreach ($list_settings['interest_groups']
            AS $mailchimp_field_id => $member_field_id
          )
          {
            $list->add_interest_group(new MCS_Interest_group(array(
              'id'        => $mailchimp_field_id,
              'member_field_id'   => $member_field_id
            )));
          }
        }

        // Merge variables.
        if (isset($list_settings['merge_variables'])
          && is_array($list_settings['merge_variables'])
        )
        {
          foreach ($list_settings['merge_variables']
            AS $mailchimp_field_id => $member_field_id
          )
          {
            $list->add_merge_variable(new MCS_Merge_variable(array(
              'tag'             => $mailchimp_field_id,
              'member_field_id' => $member_field_id
            )));
          }
        }

        $settings->add_mailing_list($list);
      }
    }

    $this->_settings = $settings;

    // The view settings are probably out-of-date, so reset them.
    $this->_view_settings = NULL;
  }



  /* --------------------------------------------------------------
   * PRIVATE METHODS
   * ------------------------------------------------------------ */

  /**
   * Handles all API requests.
   *
   * @access  private
   * @param   string  $method The API method to call.
   * @param   array   $params An additional parameters to include in the API call.
   * @return  void
   */
  private function _call_api($method = '', Array $params = array())
  {
    // Do we have a valid connector?
    if ( ! $this->_initialize_connector())
    {
      throw new MCS_Data_exception('Unable to initialize connector object.');
    }

    if ( ! method_exists($this->_connector, $method))
    {
      throw new MCS_Api_exception('Unknown API method "' .$method .'".');
    }
    $result = call_user_func_array(array($this->_connector, $method), $params);
    // Was the connector method called successfully?
    if ($result === FALSE)
    {
      throw new MCS_Api_exception($this->_connector->errorMessage,
        $this->_connector->errorCode);
    }

    // Was the API method called successfully.
    if ($this->_connector->errorCode)
    {
      throw new MCS_Api_exception($this->_connector->errorMessage,
        $this->_connector->errorCode);
    }
    return $result;
  }


  /**
   * Attempts to initialise the MailChimp API 'connector'.
   *
   * @access  private
   * @return  bool
   */
  private function _initialize_connector()
  {
    // Once the connector has been created, we're good.
    if ($this->_connector)
    {
      return TRUE;
    }

    // If we have an API key, create the connector.
    $this->_connector = $this->_settings->api_key
      ? new MCAPI($this->_settings->api_key)
      : NULL;

    return ( ! is_null($this->_connector));
  }


  /**
   * Retrieves the user account details from the API.
   *
   * @access  private
   * @return  void
   */
  private function _load_account_from_api()
  {
    /**
     * Reset the account details. We do this first in case
     * the API call throws an exception.
     */

    $this->_api_account = new MCS_Api_account();

    // Make the API call.
    $api_account = $this->_call_api('getAccountDetails');
    $this->_api_account->populate_from_array($api_account);
  }


  /**
   * Retrieves the mailing lists from the API.
   *
   * @access  private
   * @return  void
   */
  private function _load_mailing_lists_from_api()
  {
    // Reset the mailing lists.
    $this->_mailing_lists = array();

    // Make the API call.
    $result = $this->_call_api('lists');

    // Parse the results.
    $lists = array();

    foreach ($result AS $r)
    {
      /**
       * Retrieve the interest 'groupings'. MailChimp now supports multiple
       * interest groups. If the list does not have any interest groups, error
       * code 211 is returned, and an exception is thrown.
       *
       * We don't want this to bring everything crashing to a halt, so we catch
       * the exception here, and only rethrow it if we don't recognise the
       * error.
       */

      try
      {
        $interest_groups = $this->_call_api('listInterestGroupings',
          array($r['id']));
      }
      catch (Exception $exception)
      {
        if ($exception->getCode() != '211')
        {
          $this->log_error($exception->getMessage() .' (error code: '
            .$exception->getCode() .')', 3);

          throw $exception;
        }

        $interest_groups = array();
      }

      // Merge variables.
      $merge_vars = $this->_call_api('listMergeVars', array($r['id']));

      // Add the list.
      $lists[] = new MCS_Mailing_list(array(
        'interest_groups' => $interest_groups,
        'id'              => $r['id'],
        'merge_variables' => $merge_vars,
        'name'            => $r['name']
      ));
    }

    $this->_mailing_lists = $lists;
  }

  /**
   * Check if Profile:Edit is installed.
   *
   * @author  Surprise Highway <http://github.com/surprisehighway>
   * @since   2.1.0
   * @access  private
   * @return  void
   */
  private function _init_profile_edit()
  {
    $this->_ee->load->model('addons_model');
    $this->_ee->load->model('channel_model');
    $this->_ee->load->model('category_model');

    if ( ! $this->_ee->addons_model->module_installed('Profile'))
    {
      return FALSE;
    }

    // this is not active record on purpose, leave it alone!
    $query = $this->db->query('SELECT `settings` FROM '.$this->db->dbprefix('extensions').' WHERE class = \'Profile_ext\' LIMIT 1');

    $this->_profile_edit_settings = @unserialize($query->row('settings'));

    $profile_edit_channel = $this->_ee->channel_model->get_channel_info($this->_profile_edit_settings['channel_id']);

    if ($profile_edit_channel->num_rows() > 0)
    {
      $row         = $profile_edit_channel->row();
      $field_group = $row->field_group;
      $cat_group   = $row->cat_group;

      $profile_edit_fields = $this->_ee->channel_model->get_channel_fields($field_group);

      if ($profile_edit_fields->num_rows() > 0)
      {
        $this->_profile_edit_member_fields = $profile_edit_fields->result();
      }

      // Get the categories, if any
      $category_groups = $this->_ee->category_model->get_category_groups(explode('|', $cat_group));
      if ($category_groups->num_rows() > 0) {

        $members_cats = array();
        foreach($category_groups->result() as $catgroup) {
          $categories = $this->_ee->category_model->get_channel_categories($catgroup->group_id);
          $members_cats[] = array(
              'id'  => $catgroup->group_id,
              'name'  => $catgroup->group_name,
              'categories' => $categories->result_array()
            );
          // Get categories
          $this->_profile_edit_member_categories = $members_cats;
        }
      }

      return TRUE;

    }

    return FALSE;

  }

  /**
   * Loads the member fields from the database.
   *
   * @access  private
   * @return  array
   */
  private function _load_member_fields_from_db()
  {

    $this->_ee->lang->loadfile('member');

    $member_fields = array();

    $member_channel_fields     = $this->_profile_edit_member_fields;
    $member_channel_categories = $this->_profile_edit_member_categories;

    // Loop on every custom fields
    foreach($member_channel_fields as $channel_field)
    {

      if ($channel_field->field_type == 'select')
      {
        $options = array();
        $raw_options = explode("\n", $channel_field->field_list_items);

        foreach ($raw_options AS $key => $value)
        {
          $options[$value] = $value;
        }
      }
      else
      {
        $options = array();
      }


      $member_fields['field_id_'.$channel_field->field_id] = array(
        'id'      => 'field_id_'.$channel_field->field_id,
        'label'   => $channel_field->field_label,
        'options' => $options,
        'type'    => $channel_field->field_type == 'select'
          ? 'select' : 'text'
      );

    }

    if (isset($member_channel_categories ) && count($member_channel_categories ) > 0)
    {
      foreach($member_channel_categories as $member_category)
      {

          $options = array();
          foreach ($member_category['categories'] as $key => $value)
          {
            $options[$value['cat_name']] = $value['cat_name'];
          }

          $member_fields['cat_group_id_'.$member_category['id']] = array(
            'id'    => 'cat_group_id_'.$member_category['id'],
            'label'   => $member_category['name'],
            'options' => $options,
            'type'    => 'select'
          );
      }
    }

    $this->_member_fields = $member_fields;

  }


  /**
   * Registers the supplied hooks.
   *
   * @access  private
   * @param   array    $hooks    The hooks to register.
   * @return  void
   */
  private function _register_hooks(Array $hooks = array())
  {
    foreach ($hooks AS $hook)
    {
      $this->_ee->db->insert(
        'extensions',
        array(
          'class'     => $this->_extension_class,
          'enabled'   => 'y',
          'hook'      => $hook['hook'],
          'method'    => $hook['method'],
          'priority'  => $hook['priority'],
          'settings'  => '',
          'version'   => $this->_version
        )
      );
    }
  }


  /**
   * Loads the settings from the database.
   *
   * @access  private
   * @return  void
   */
  private function _load_settings_from_db()
  {
    $settings = new MCS_Settings();

    // Load the settings from the database.
    $db_settings = $this->_ee->db->select('settings')->get_where(
      'mailchimp_subscribe_settings',
      array('site_id' => $this->_site_id),
      1
    );

    // If we have saved settings, parse them.
    if ($db_settings->num_rows() > 0)
    {
      $this->_ee->load->helper('string');

      $site_settings = unserialize(strip_slashes(
        $db_settings->row()->settings));

      $settings->populate_from_array($site_settings);
    }

    $this->_settings = $settings;
  }


  /**
   * Subscribes a member to the active mailing lists, or updates
   * a member's existing subscriptions.
   *
   * @access  private
   * @param   string  $member_id    The member ID.
   * @param   bool    $update       Are we updating existing subscriptions?
   * @return  void
   */
  private function _update_member_subscriptions($member_id = '', $update = FALSE)
  {

    // Check that we have a member ID.
    if ( ! $member_id)
    {
      throw new MCS_Data_exception('Unable to update member subscriptions
        (missing member ID).');
    }

    // Retrieve the member.
    $members = $this->get_members(array('member_id' => $member_id));

    if (count($members) !== 1)
    {
      throw new MCS_Data_exception('Error retrieving member ID ' .$member_id);
    }

    // Convenience.
    $member = $members[0];
    // Is the member banned?
    if (in_array($member['group_id'], array('2', '4')))
    {
      return;
    }

    /**
     * Process the mailing lists.
     */

    $subscribe_to     = array();
    $unsubscribe_from = array();

    foreach ($this->_settings->mailing_lists AS $list)
    {
      /**
       * If there is no trigger field, the member must be
       * subscribed to the list.
       */

      if ( ! $list->trigger_field)
      {
        $subscribe_to[] = $list;
        continue;
      }

      /**
       * If there is a trigger field, we need to check whether
       * the member has opted-in to this list.
       */

      if (isset($member[$list->trigger_field])
        && $member[$list->trigger_field] === $list->trigger_value
      )
      {
        $subscribe_to[] = $list;
      }
      else
      {
        $unsubscribe_from[] = $list;
      }
    }

    // Do we have an work to do?
    if (count($subscribe_to) == 0
      && ($update == FALSE OR count($unsubscribe_from) == 0)
    )
    {
      return;
    }

    // Let's get APIing. That's totally a word.
    foreach ($subscribe_to AS $list)
    {
      // Merge variables.
      $merge_vars = array();
      foreach ($list->merge_variables AS $tag => $val)
      {
        if ($val->tag && isset($member[$val->member_field_id]))
        {
          $merge_vars[$val->tag] = $member[$val->member_field_id];
        }
      }
      // Interest groups.
      $groupings = array();

      foreach ($list->interest_groups AS $id => $val)
      {
        if ($val->id && isset($member[$val->member_field_id]))
        {
          $groupings[$val->id] = array(
            'id'      => $val->id,
            'groups'  => $member[$val->member_field_id]
          );
        }
      }

      if ($groupings)
      {
        $merge_vars['GROUPINGS'] = $groupings;
      }

      /**
       * @since   2.0.1
       * @see     http://www.mailchimp.com/api/rtfm/listsubscribe.func.php
       *
       * Passing a blank $merge_vars array will fail. We should either pass an
       * empty string or array('').
       */

      if ( ! $merge_vars)
      {
        $merge_vars = '';
      }

      // Finally we can make the API call.
      $this->_call_api('listSubscribe', array(
        $list->id,
        $member['email'],
        $merge_vars,
        'html',         // Email format.
        FALSE,          // Double opt-in?
        (bool) $update  // Update existing subscription?
      ));
    }

    // Process the unsubscriptions.
    if ($update)
    {
      foreach ($unsubscribe_from AS $list)
      {
        $this->_call_api('listUnsubscribe', array($list->id, $member['email']));
      }
    }
  }

}


/* End of file    : mailchimp_model.php */
/* File location  : third_party/mailchimp_subscribe/models/mailchimp_model.php */
