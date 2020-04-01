<?php
/**
 * Zoom Api Class and any helper functions
 * handles the oAuth, meeting creation, and links to meeting
 * this file should contain most of the api calls to the Zoom API service
 */
namespace Zoom_Meeting;
use \DateTime;
use \DateTimeZone;
use \DateInterval;



add_shortcode('test_zoom_class', __NAMESPACE__.'\\test_zoom_class');

function test_zoom_class(){
    $zoom = new Zoom_Create_Meeting(get_current_user_id());
    if($zoom->status != 'needs_authorization'){
        $zoom->update_user_settings();
        $zoom->create_meeting();
        $response = array(
            $zoom->settings_response,
            $zoom->meeting_response
        );
        echo '<pre>';var_dump($response);echo '</pre>';
    } else {
        return $zoom->button;
    }
}

/**
 * Zoom Api Class
 * handles the oAuth, meeting creation, and links to meeting
 */

 class Zoom_Oauth {
     public $authorize_url;
     public $button;
     public $user_is_authorized;
     public $token_is_valid;
     public $needs_authorization;
     public $token;
     public $user_id;
     protected $basic;
     public $status;

     public function __construct($user_id){
         if(!empty($user_id)){
            $this->user_id = $user_id;
            $this->authorize_url = 'https://zoom.us/oauth/authorize?response_type=code&client_id=eVHbekKR7e9290wrc8OnA&redirect_uri=https%3A%2F%2Fmsp-media.org%2Fnew-group-meeting%2F';
            $this->button =  '<a class="button" href="'.$this->authorize_url.'">Authorize Our App</a>';
            $clientID = get_option('zoom_oauth_client_ID');
            $clientSecret = get_option('zoom_oauth_client_secret');
            $this->basic = base64_encode($clientID.':'.$clientSecret);
            $this->set_status();
         } else {
            trigger_error('You must be logged in to create a group.', E_USER_ERROR);
         }
     }

     private function set_status(){
        $this->user_is_authorized();
        $this->token_is_valid();
        $this->needs_authorization();
        $response = array(
            'button'=>$this->button,
        );
         //three scenarios
        // 1. has token and token is good
        if($this->token_is_valid){
            $this->status = 'authorized'; //do nothing
        }
        // 2. has token and token is expired
        if($this->user_is_authorized && !$this->token_is_valid){
            $this->refresh_token(); //refresh token
            $this->status = 'refreshed_token';
        }
        // 3. user is not authorized 
        if(!$this->user_is_authorized ){
            if(isset($_GET['code'])) {
                $this->set_token($_GET['code']);
                $this->status = 'got_token';
            } else {
                $this->status = 'needs_authorization';
            }  
        }
     }

     /**
      * Setters
      * these functions set the values in the wp usermeta table either directly from zoom or by calculating those values after receiving response from zoom
      */
     
     public function set_refresh_token(){
        $this->set_refresh_token();
        $url = 'https://zoom.us/oauth/token';
        $body = array(
            'grant_type'=>'refresh_token',
            'refresh_token'=>$this->refresh_token,
            'redirect_uri'=>'https://msp-media.org/new-group-meeting/'
        );
        $headers = array(
            "Authorization"=> 'Basic '.$this->basic
        );
        $args = array(
            'body'=>$body,
            'headers' => $headers
        );
        $this->token_response = json_decode(wp_remote_retrieve_body(wp_remote_post($url, $args)));
        $this->token = $this->token_response->access_token;
        $this->update_user_meta();

     }

     public function set_token($code){
        $url = 'https://zoom.us/oauth/token';
        $body = array(
            'grant_type'=>'authorization_code',
            'code'=>$code,
            'redirect_uri'=>'https://msp-media.org/new-group-meeting/'
        );
        $headers = array(
            "Authorization"=> 'Basic '.$this->basic
        );
        $args = array(
            'body'=>$body,
            'headers' => $headers
        );
        $this->token_response = json_decode(wp_remote_retrieve_body(wp_remote_post($url, $args)));
        $this->update_user_meta();
    }

    private function update_user_meta(){
        update_user_meta($this->user_id, 'vsg-zoom-token', $this->token_response->access_token);
        update_user_meta($this->user_id, 'vsg-zoom-refresh', $this->token_response->refresh_token);
        $this->set_expiration();
    }

    private function set_expiration(){
        $expiration = new DateTime(NULL, new DateTimeZone(get_option('timezone_string')));
        $expiration->add(new DateInterval('PT1H'));
        update_user_meta($this->user_id, 'vsg-zoom-token-expiration', $expiration->format('Y-m-d H:i:s'));
    }

    /**
     * Getters
     * these pull from wp user meta table to get current existing values
     */
    private function get_refresh_token(){
        $this->refresh_token = get_user_meta(
            $this->user_id,
            'vsg-zoom-refresh',
            true
        );
    }

    private function get_expiration(){
        return get_user_meta(
            $this->user_id,
            'vsg-zoom-token-expiration',
            true
        );
    }

    public function user_is_authorized(){
        $token = get_user_meta(
            $this->user_id,
           'vsg-zoom-token',
           true
        );
        $this->token = $token;
        if(!empty($token)){
            $this->user_is_authorized = true;
        } else {
           $this->user_is_authorized = false;
        }
    }

    public function token_is_valid(){
        $now = new DateTime(NULL, new DateTimeZone(get_option('timezone_string')));
        $expiration = new DateTime($this->get_expiration(), new DateTimeZone(get_option('timezone_string')));
        if($now < $expiration){
            $this->token_is_valid = true;
        } else {
            $this->token_is_valid = false;
        }
     }

     private function needs_authorization(){
        $this->needs_authorization = false;
        if(!$this->user_is_authorized){
            $this->needs_authorization = true;
        }
     }

 }// end class Zoom_Oauth

 /**
  * class zoom create meeting
  * use this class to create a new group meeting and get the url for that meeting
  */
class Zoom_Create_Meeting extends Zoom_Oauth {
    public $meeting_form;
    public $meeting_response;
    public $settings_response;

    public function __construct( $user_id, $args = NULL){
        parent::__construct($user_id);
        $this->set_meeting_form();
        $this->set_meeting_args();
        $this->set_settings_args();        
    }

    public function set_meeting_form(){
        $this->meeting_form = '<form>
        <label for="group">Group</label>
        <input id="group" />
        </form>';
    }

    public function set_meeting_args() {
        $recurrence = array(
           'type'=>2,
           'weekly_days'=> '2, 4, 6',
            'end_times' => 6
        );
        $recurrence = (object) $recurrence;
        $settings = array(
            'approval_type'=>2,
            'host_video'=>true,
            'participant_video'=>true, 
            'join_before_host'=>false,
            'enable_waiting_room'=>true
        );
        $settings = (object) $settings;
        
        $meeting = array(
            'topic'=>'Small Group Bible Study',
            'type'=>8,
            'duration'=>40,
            'timezone'=>get_option('timezone_string'),
            'start_time'=>'2020-03-30T19:00:00',
            'time_zone'=>'America/New_York', 
            'recurrence' => $recurrence,
            'settings'=>$settings   
        );
        $this->meeting_args = $meeting;
    }

    public function set_settings_args(){
        $in_meeting = array(
            'non_verbal_feedback'=>true,
            'chat'=>true,
            'private_chat'=>false,
            'co_host'=> true,
            'remote_support' => true,
            'waiting_room'=>true,
            'show_meeting_control_toolbar'=>true
        );
        $in_meeting = (object) $in_meeting;
        $email_notifications = array(
            "jbh_reminder" => false,
            "cancel_meeting_reminder" => false,
            "alternative_host_reminder" => false,
            "schedule_for_reminder"=> false
        );
        $email_notifications = (object)$email_notifications;
        $this->user_settings = array(
           'in_meetign' => $in_meeting,
           'email_notifications' => $email_notifications
        );
    }

    public function update_user_settings(){
        $url = 'https://api.zoom.us/v2/users/me/settings';
        $body = json_encode($this->user_settings);
        $headers = array(
            "Authorization"=> 'Bearer '.$this->token,
            'Content-Type' => 'application/json; charset=utf-8'
        );
        $args = array(
            'body'=>$body,
            'headers' => $headers,
            'method'      => 'POST',
            'data_format' => 'body',
        );
        $this->settings_response = json_decode(wp_remote_retrieve_body(wp_remote_post($url, $args)));
    }

    public function create_meeting(){
        $url = 'https://api.zoom.us/v2/users/me/meetings';
        $body = json_encode($this->meeting_args);
        $headers = array(
            "Authorization"=> 'Bearer '.$this->token,
            'Content-Type' => 'application/json; charset=utf-8'
        );
        $args = array(
            'body'=>$body,
            'headers' => $headers,
            'method'      => 'POST',
            'data_format' => 'body',
        );
        $this->meeting_response = json_decode(wp_remote_retrieve_body(wp_remote_post($url, $args)));
    }

}



?>