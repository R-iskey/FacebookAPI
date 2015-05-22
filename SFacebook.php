<?php

/**
 * @package Facebook API General clas
 * @author: R.iskey / 4r.iskey@gmail.com
 * @copyright 2015 Luzy Standler 
 * 
 * First step: require autoload.php, which is include Facebook SDK
 * Second:  Work auth() function from FacebookController auth() action
 *
 * @var $appId - facebook application id
 * @var $appSecret - facebook app secret key
 * @var $redirect_url - redirect uri, after facebook login success {this class auth() function}
 * @var $_accessToken - Long term access token(default 0~60) days
 *  @link:  https://developers.facebook.com/docs/facebook-login/access-tokens#termtokens
 * @var $token_credentials - existed access_token from DB users table
 */

require __DIR__ . '/autoload.php';

use Facebook\FacebookRedirectLoginHelper;
use Facebook\FacebookRequestException;
use Facebook\Entities\AccessToken;
use Facebook\FacebookSession;
use Facebook\FacebookRequest;
use Facebook\GraphUser;

Class SFacebook extends CApplicationComponent
{
    public $appId;
    public $appSecret;
    public $redirect_url;

    private $_accessToken = null;
    private $token_credentials;


    ######## Initialize function ########

    /** 
    * [_getSession description]
    *
    * @param $FacebookRedirectLoginHelper [Graph api object]
    * 
    * Get FB session from redirect url first time only,
    * when user authorizating, each _getSession()
    * call created new FB session 
    * with specified access token.
    * 
    * @return current $session
    * 
    */
    private function _getSession($FacebookRedirectLoginHelper = null){

        $session = false;

        if ($FacebookRedirectLoginHelper != null) {
            try {
              $session = $FacebookRedirectLoginHelper->getSessionFromRedirect();
            } catch( FacebookRequestException $ex ) {
                throw new CException( 'Facebook Session returned an error: ' . $ex->getMessage() );
            } catch( Exception $ex ) {
               throw new CException( 'Session returned an error: ' . $ex->getMessage() );
            }
        }
        
        /* Create new session */
        if (!$session) {
            FacebookSession::setDefaultApplication($this->appId, $this->appSecret);
            $session = new FacebookSession($this->_accessToken);      
        }   

        return $session;
    }

    /**
     * [setAccessToken description]
     * set $_accessToken
     * @param token [string]
     */
    private function setAccessToken($token){

        $token = trim($token);
        if (empty($token)) {
            throw new \InvalidArgumentException('Invalid access token');
        }

        $this->_accessToken = $token;
    }

    /**
     * [getPageAccessToken description]
     * @param  $page[int] 
     * @return [int/false]
     */
    private function getPageAccessToken($page){

        if (is_numeric($page)) {
            $access_token =  ServiceFacebook::model()->findByAttributes(array(
                'service_facebook_id'=>$page,
                'user_fid'=> Yii::app()->user->id,
                'page_type'=> 0,
            ));
            return $access_token->access_token;
        }
        return false;
    }

    /**
     * [connect description]
     * get connection with FB
     * @return [true/false]
     */
    private function connect(){
        
        if (!Yii::app()->params['facebookId']) {
            return false;
        }
   
        $this->token_credentials = ServiceFacebook::model()->findByAttributes(array(
            'service_facebook_id'=>Yii::app()->params['facebookId'],
            'user_fid'=>Yii::app()->user->id,
        ));
        
        if (!$this->token_credentials) {
            return false;
        }

        $this->setAccessToken($this->token_credentials['access_token']);

        return true;
    }

    /**
    * Check connection 
    * @return true/false
    */
    public function connected(){
        return Yii::app()->params['facebookId'] ? true : false;
    }

    /**
     * [auth description]
     * Autorizating function
     * @get User Profile, User Permissions, User Pages
     * @return [true/false]
     */
    public function auth(){

        /* Initialization facebook config */
        FacebookSession::setDefaultApplication($this->appId, $this->appSecret);
        $helper = new FacebookRedirectLoginHelper($this->redirect_url);
        /* Oauth 2.0 code */
        $oAuthCode = Yii::app()->request->getQuery('code');

        if (!isset($oAuthCode)) {
            $scope = array(
                'public_profile',
                'publish_actions', //Add comments, posts to profile feed
                'publish_pages', //Add comments, posts to page feed
                'read_stream', //For timeline 
                'manage_pages', //For users custom page s
                'manage_notifications', //for notifications
            );
            /* Redirect user to FB login page */
            Yii::app()->request->redirect($helper->getLoginUrl($scope));

        } 
        /* Get session data from redirect */
        $session = $this->_getSession($helper);

        if ($session) {
            //Get access token and Exchange the short-lived token with a long-lived token.
            $shortLivedToken = $session->getAccessToken();
            if (isset($shortLivedToken)) {
                $this->_accessToken = $shortLivedToken->extend();
                if (!$this->_accessToken) {
                    throw new CException('Long time auth key doesn\'t generated.');
                }

            } elseif ($helper->getError()) {
                // There was an error (user probably rejected the request)
                echo '<p>Error: ' . $helper->getError();
                echo '<p>Code: ' . $helper->getErrorCode();
                echo '<p>Reason: ' . $helper->getErrorReason();
                echo '<p>Description: ' . $helper->getErrorDescription();
                exit;
            }

            /** 
            *  Try to get user data and user pages 
            *  with batch request
            */
            try {
              $params = [
                [
                  "method"  => "GET",
                  "relative_url"  => "me?fields=id,name",
                ],
                [
                  "method"  => "GET",
                  "relative_url"  => "me/accounts",
                ],
                [
                  "method"  => "GET",
                  "relative_url"  => "me/permissions",
                ] 
              ];
              // urlencode() required!
              $user_data = (new FacebookRequest($session, 'POST',
                '?batch='.urlencode(json_encode($params)).'&include_headers=false')
              )->execute()->getGraphObject()->asArray();

            } catch(FacebookRequestException $e) {
                throw new CException('Graph returned an error: ' . $e->getMessage());
            } catch(Exception $e) {
                throw new CException('Facebook SDK returned an error: ' . $e->getMessage());
            } 

            $serviceFacebook = new ServiceFacebook();
            $serviceFacebook->deleteAllByAttributes(array(
                'user_fid'=>Yii::app()->user->id
            ));

            //user info
            if ($user_data[0]->code == 200) {
                $user_profile = json_decode($user_data[0]->body);

                $serviceFacebook->service_facebook_id = $user_profile->id;
                $serviceFacebook->access_token = $this->_accessToken;
                $serviceFacebook->user_fid = Yii::app()->user->id;
                $serviceFacebook->user_id  = Yii::app()->user->id;
                $serviceFacebook->name = $user_profile->name;
                $serviceFacebook->publish = 1;
                $serviceFacebook->page_type = 1; // 1 = user profile

                $serviceFacebook->save();

                /**
                 * update user profile
                 */
                $user = User::model()->findByPk(Yii::app()->user->id);
                $user->facebook_id = $user_profile->id;
                $user->save();
            }

            //user pages info
            if ($user_data[1]->code == 200) {
                $user_page = json_decode($user_data[1]->body);

                // If user have pages then save user pages multiple data
                if (!empty($user_page->data)) {
                    foreach ($user_page->data as $key => $page) {
                        $serviceFacebook = new ServiceFacebook;

                        $serviceFacebook->service_facebook_id = $page->id;
                        $serviceFacebook->user_fid = Yii::app()->user->id;
                        $serviceFacebook->user_id = Yii::app()->user->id;
                        $serviceFacebook->name = $page->name;
                        $serviceFacebook->category = $page->category;
                        $serviceFacebook->access_token = $page->access_token;
                        $serviceFacebook->publish = 0;
                        $serviceFacebook->page_type = 0; // 0 = user page

                        $serviceFacebook->save();
                    }

                }
            }

            //user permissions
            if ($user_data[2]->code == 200) {
                $permissions = json_decode($user_data[2]->body);

                foreach ($permissions->data as $permission) {
                    $serviceFacebookPermissions = new ServiceFacebookPermissions;

                    if ($permission->status == 'granted' ) {
                        $serviceFacebookPermissions->status = 1;
                    }
                    $serviceFacebookPermissions->permission = $permission->permission;
                    $serviceFacebookPermissions->save();
                }
            }
            

        } else {
            throw new CException('Facebook Session is not defined');
        }

        return true;
    }

    /**
     * [deauth description]
     * delete ServiceFacebook table user entries 
     * and delete all data from tables use foreign keys
     * 
     * @return [true]
     */
    public function deauth(){

        ServiceFacebook::model()->deleteAllByAttributes(array(
            'user_fid'=>Yii::app()->user->id
        ));
       
        $user = User::model()->findByPk(Yii::app()->user->id);
        $user->facebook_id = '';
        $user->save();

        return true;
    }

    /**
     * NOT FINISHED
     * [revoke description]
     * Delete users permissions
     * @return [true/false]
     */
    public function revoke(){
        $user_data = (new FacebookRequest($session, 'DELETE',
            '/me/permissions'))->execute()->getGraphObject();

        return true;
    }


    ######## POST(Action) Functions ########

    /**
     * [update description]
     * Post new status from user or user page 
     * @param  post [array] - Post text, image
     * @param  pages [array] - checkbox checked data
     * @return [true/false]
     */
    public function update(array $post, $pages){

        if (empty($post)) {
            return false;
        }

        try 
        {
            $this->connect();
            //Get pages which from post to update
            if (!empty($pages)) {
                foreach ($pages as $page) {
                    $token = $this->getPageAccessToken($page);
                    $this->setAccessToken($token);
                    //get session for page 
                    $session  = $this->_getSession();

                    $post_id = (new FacebookRequest(
                      $session, 'POST', "/$page/feed", $post
                    ))->execute()->getGraphObject();
                }
            } 
            else {
                //get session for user
                $session = $this->_getSession();
                $post_id = (new FacebookRequest(
                  $session, 'POST', '/me/feed', $post
                ))->execute()->getGraphObject();
            }

            $post_ids[] = $post_id->getProperty('id');

            return $post_ids; 

        } catch(Exception $e) {
            return false;
        }
    }

    /**
     * @param  $feed_id [string] - post id
     * @param  $is_page [string] - user's page id
     * @return [true/false]
     */
    public function like($feed_id, $is_page){

        if (empty($feed_id)) {
            return false;
        }

        $id = explode('_',$feed_id);
        $user_id = $id[0];
     
        try
        {
            $this->connect();
            if ($is_page) {
                $token = $this->getPageAccessToken($is_page);
                $this->setAccessToken($token);
            }
            $session = $this->_getSession();
            
            /* Get Likes*/
            $likes = (new FacebookRequest(
                $session,'GET',"/$feed_id/likes"
                ))->execute()->getGraphObject()->asArray();

            $command = 'POST';

            if ( !empty($likes['data']) ) {
                foreach ($likes['data'] as $key => $user) {
                    if ($user->id == $user_id) {
                        $command = 'DELETE'; 
                    }
                }
            }
            
            $toLike = (new FacebookRequest(
                $session,$command,"/$feed_id/likes"
                ))->execute()->getGraphObject();

            // if 'like' successfully updated, than refresh this feed from database
            $this->updateFeedFromDB(array(
                    'feed_id'  => $feed_id,
                    'command'  => $command,
                    'user_id'  => $user_id,
                    'type'   => 'like'
                    ), $is_page);  

            return true;  
        } 
        catch (Exception $e) {
            return false;
        }
    }

    /**
     * [comment description]
     * @param  $comment_data [array] - comment message/id
     * @param  $is_page [string] - user's page id
     * @return [true/false]
     */
    public function comment(array $comment_data, $is_page){
        
        try 
        {
            $this->connect();
            if ($is_page) {
                $token = $this->getPageAccessToken($is_page);
                $this->setAccessToken($token);
            }
            $session = $this->_getSession();

            $feed_id = $comment_data['status_id'];
            $id = explode('_',$feed_id);
            $user_id = $id[0];

            $cutMessage = substr($comment_data['comment'], 0, 600);

            $response = (new FacebookRequest(
                $session,'POST',"/$feed_id/comments",
                    array ('message' => $cutMessage)
            ))->execute()->getGraphObject();

            $comment_id = $response->getProperty('id');
            $username = ServiceFacebook::model()->findByAttributes(array(
                'service_facebook_id' => $user_id,
                'user_fid' => Yii::app()->user->id
            ));

            $this->updateFeedFromDB(array(
                'comment_id' => $comment_id,
                'feed_id'  => $feed_id,
                'user_id'  => $user_id,
                'message'  => $cutMessage,
                'user_name'=> $username->name,
                'type'   => 'comment'
            ),$is_page);  
            return true;

        } 
        catch (FacebookRequestException  $e) {
            return false;
        }
    }


    ######## GET data functions From Facebook ########

    /**
     * [getNotifications saveNotifications description]
     * get User notifications and save
     * @return [true/false]
     */
    public function getNotifications(){

        try 
        {
            $this->connect();
            $session = $this->_getSession();
            $notifications = (new FacebookRequest(
              $session, 'GET', '/me/notifications'
            ))->execute()->getGraphObject();
        } 
        catch (Exception $e) {
            return false;
        }
        return $notifications;
    }

    /**
     * get user,user page feed
     * @param  $command - command or feed id
     * @param  $pages -  if pages
     * @return $feed_data
     */
    public function getFeed($pages = null, $command='me/feed'){

        try 
        {
            $this->connect();
            $session = $this->_getSession();

            /** 
            * This use only if you want to receive only new data,
            * ignored old post data comments and likes updates
            *
            * $since = '';
            * $ServiceFacebookFeed = ServiceFacebookFeed::model()->byDate()->find();
            * if (!empty($ServiceFacebookFeed->created)) {
            *    $since = strtotime($ServiceFacebookFeed->created);
            * }
            */
            $urlParams = array(
                'limit' => 50,
                'offset'=> 0,
                'fields'=> 'id,from,message,story,picture,link,created_time,likes,comments.limit(120){message,from,created_time}',
                //'since'=> $since
            );
            $url = http_build_query($urlParams);

            if ($pages) {
               foreach ($pages as $model) {
                   $page_id = $model->service_facebook_id;
                   $params[] = array(
                        'method' => 'GET',
                        'relative_url' => $page_id . "/" . feed . "?" . $url,
                    );
                }
            } 

            $params[] = array(
                'method' => 'GET',
                'relative_url' => $command . "?" . $url,
            );

            //Send batch request
            $feed_data = (new FacebookRequest($session, 'POST',
                '?batch='.urlencode(json_encode($params)).'&include_headers=false')
            )->execute()->getGraphObject()->asArray();  
          
        }
        catch (Exception $e) {
            return false;
        }

        return $feed_data;
    }

   /**
    * [getPages description]
    *
    * @param  $active[boolean] - search by active pages
    * @return false/[model object]
    */
    public function getPages($active) {

        $serviceFacebookPages = false;

        return $active 
            ?  $serviceFacebookPages = ServiceFacebook::model()->activePages()->findAll()
            :  $serviceFacebookPages = ServiceFacebook::model()->pages()->findAll();
    }

    /**
     * [getPageFeed description]
     * Different between getFeed() is this function send batch request
     * @link https://developers.facebook.com/docs/graph-api/making-multiple-requests
     * @param  $pages [object]
     * @return [graph object/false]
     */

    ######## SAVE functions work with MySql ########

    public function saveFeed($page_feed_data){ 
    
        if (!empty($page_feed_data)) {
            /* Delete active status to all entries  */
            ServiceFacebookFeed::model()->updateAll(
                array('active' => 0),
                array('condition' => 'user_fid=:user_fid',
                      'params'=>array(
                        'user_fid'=> Yii::app()->user->id))
            );

            $serviceFacebookData = ServiceFacebook::model()->findAllByAttributes(array(
                'user_fid' => Yii::app()->user->id
            ));

            foreach ($serviceFacebookData as $rows) {
                $pageType[$rows->service_facebook_id] = $rows->page_type;
            }

            foreach ($page_feed_data as $page_key => $page) {
                if ($page->code == 200) {
                    // decode received data from batch request
                    $feeds = json_decode($page->body);
                    if (!empty($feeds->data)) {
                        foreach ((array)$feeds->data as $key => $data) {

                            $is_liked = 0;
                            $apiUpdateKeys[] = $data->id; //for 2 arrays difference
                            $ids = explode('_', $data->id);  //get User/page id
                            $facebook_fid = $ids[0];
                           
                            $serviceFacebookFeed = ServiceFacebookFeed::model()->findByAttributes(array(
                                'facebook_feed_id' => $data->id,
                                'user_fid' => Yii::app()->user->id,
                                'facebook_fid' => $facebook_fid
                            ));

                            // Get like status
                            $likes = (array)$data->likes->data;
                            if (!empty($likes)) {
                                foreach ($likes as $like) {
                                    if ($like->id == $facebook_fid) {
                                        $is_liked = 1;
                                    }
                                }
                            }
                            unset($data->likes);
                            
                            if ($serviceFacebookFeed != null) {
                                //Save received likes 
                                if ($serviceFacebookFeed->like_count != count($likes)) {
                                    $serviceFacebookFeed->like_count  = count($likes);
                                }
                                
                                if ($serviceFacebookFeed->is_liked != $is_liked) {
                                    $serviceFacebookFeed->is_liked  = $is_liked;
                                }
                                
                            } else {
                                $serviceFacebookFeed = new ServiceFacebookFeed();
                    
                                $serviceFacebookFeed->facebook_feed_id = $data->id;
                                $serviceFacebookFeed->facebook_fid = $facebook_fid;
                                $serviceFacebookFeed->user_fid = Yii::app()->user->id;
                                $serviceFacebookFeed->created = date('Y-m-d H:i:s', strtotime($data->created_time));
                                $serviceFacebookFeed->active = 1;
                                $serviceFacebookFeed->data = serialize($data);
                                $serviceFacebookFeed->is_liked = $is_liked;
                                $serviceFacebookFeed->like_count = count($likes);
                                $serviceFacebookFeed->page_type = $pageType[$facebook_fid];

                                
                            }

                            if(!$serviceFacebookFeed->save()){
                               return false;
                            }
                           
                           
                            // Get comments and save
                            $comments = $data->comments->data;
                            if (!empty($comments)) {
                                foreach ($comments as $comment) {

                                    $serviceFacebookComment = ServiceFacebookComment::model()->findByAttributes(array(
                                        'service_facebook_comment_id' => $comment->id,
                                        'service_facebook_feed_fid' => $data->id,
                                        'user_fid' => Yii::app()->user->id,
                                    ));

                                    if (!$serviceFacebookComment) {
                                        $serviceFacebookComment = new serviceFacebookComment();

                                        $serviceFacebookComment->service_facebook_comment_id = $comment->id;
                                        $serviceFacebookComment->service_facebook_feed_fid = $data->id;
                                        $serviceFacebookComment->user_fid = Yii::app()->user->id;
                                        $serviceFacebookComment->message = $comment->message; 
                                        $serviceFacebookComment->from = serialize($comment->from); 
                                        $serviceFacebookComment->created = date('Y-m-d H:i:s', strtotime($comment->created_time));
                                        $serviceFacebookComment->readed = 0;
                                        
                                        if (!$serviceFacebookComment->save()) {
                                            return false;
                                        }
                                    }
                                }           
                            }
                        } 
                    }  
                }
            }

            /**
             * Delete entries which removed from Facebook 
             */
            if (!empty($apiUpdateKeys) && !empty($pageType)) {
                // After save all data get difference between api and db data's and delete
                $this->deleteRemovedEntries(array (
                    'api_keys' => $apiUpdateKeys, 
                    'users_id' => $pageType
                ));
            }
        }

        return true;    
    }

    public function saveNotifications($notifications){
        if (!empty($notifications)) {
            /* Delete active status to all entries  */
            ServiceFacebookNotification::model()->updateAll(
                array(
                    'active' => 0
                ),
                array(
                    'condition'=>'user_fid=:user_fid',
                    'params'=>array('user_fid'=>Yii::app()->user->id)
                )
            );
            foreach ((array)$notifications as $facebook_id => $notification) {
                foreach ((array)$notification['data'] as $data) {

                    $serviceFacebookNotification = ServiceFacebookNotification::model()->findByAttributes(array(
                        'facebook_notification_id'=>$data->id,
                    ));
                
                    if (!$serviceFacebookNotification) {
                        $serviceFacebookNotification = new ServiceFacebookNotification;
                    
                        $serviceFacebookNotification->facebook_notification_id = $data->id;
                        $serviceFacebookNotification->facebook_fid = $data->to->id;
                        $serviceFacebookNotification->user_fid = Yii::app()->user->id;
                        $serviceFacebookNotification->data = serialize($data);
                        $serviceFacebookNotification->created = date('Y-m-d H:i:s', strtotime($data->created_time));
                        $serviceFacebookNotification->active = 1;
                        
                        $serviceFacebookNotification->save();
                    }
                }
            }

            /**
             * search latest notifications
             */
            $minNotification = ServiceFacebookNotification::model()->findAll(array(
                'condition'=>'user_fid=:user_fid',
                'params'=>array(':user_fid'=>Yii::app()->user->id),
                'order'=>'facebook_notification_id DESC',
                'limit'=>350
            ));

            /**
             * remove entries older than last 500
             */
            if($minNotification[349])
            {
                ServiceFacebookNotification::model()->deleteAll(
                    'created<:created AND user_fid=:user_fid',
                    array(':created'=>$minNotification[349]->created, ':user_fid'=>Yii::app()->user->id)
                );
            }
        }    
    }


    /**
    * [updateFeedFromDB description]
    * Update comments and likes from DB
    * this method for realtime update 
    * @param $data [array]
    * @param $is_page [string] - user's page id
    */
    public function updateFeedFromDB(array $data, $is_page){

        if ($data['type'] == 'like') {
            // if update user content
            $serviceFacebookFeed = ServiceFacebookFeed::model()->findByAttributes(array(
                    'facebook_feed_id' => $data['feed_id'],
                    'user_fid' => Yii::app()->user->id
            ));
            
            if (!empty($serviceFacebookFeed)) {
                if ($serviceFacebookFeed->is_liked) {
                    $serviceFacebookFeed->is_liked = 0;
                    $serviceFacebookFeed->like_count--; 
                } else {
                     $serviceFacebookFeed->is_liked = 1;
                     $serviceFacebookFeed->like_count++; 
                }
                if ($serviceFacebookFeed->save())
                    return true;
            }

        }

        elseif ($data['type'] == 'comment') {
            $from = new stdClass();
            $from->id = $data['user_id'];
            $from->name = $data['user_name'];

            $serviceFacebookComment = new ServiceFacebookComment();

            $serviceFacebookComment->service_facebook_comment_id = $data['comment_id'];
            $serviceFacebookComment->service_facebook_feed_fid = $data['feed_id'];
            $serviceFacebookComment->user_fid = Yii::app()->user->id;
            $serviceFacebookComment->message = $data['message'];
            $serviceFacebookComment->created =  date('Y-m-d H:i:s', strtotime("now"));
            $serviceFacebookComment->readed  = 1;
            $serviceFacebookComment->from  = serialize($from);

            if (!$serviceFacebookComment->save()){
                return false;
            }
        }

        return false;
    }

    /**
     * [deleteOldEntities description]
     * Delete removed entries from database
     * @param  $data [array]
     * @return [boolean]
     */
    public function deleteRemovedEntries(array $data){

            $users_id = array_keys($data['users_id']);
            $apiUpdateKeys = $data['api_keys'];

            foreach ($users_id as $facebook_fid => $page_type) {

                $criteria = new CDbCriteria(); 
                $criteria->select = 'facebook_feed_id';
                $criteria->condition = 'user_fid ='.Yii::app()->user->id.' AND facebook_fid = '.$facebook_fid;
                $result = ServiceFacebookFeed::model()->findAll($criteria);

                if (!empty($result)) {
                    /* New update key array for db statuses */
                    foreach ((array)$result as $UKey) {
                        $dbUpdateKeys[] = implode($UKey->getAttributes('facebook_feed_id'));
                    }

                    /* Sorting */
                    sort($dbUpdateKeys);
                    sort($apiUpdateKeys);

                    /* Get doesn't exists values */
                    $deleteArr = array_diff($dbUpdateKeys,$apiUpdateKeys);
                    
                    if (!empty($deleteArr)) {
                        /* Deleted from db unexistes statuses*/
                        $criteriaForDelete = new CDbCriteria;
                        $criteriaForDelete->addInCondition('facebook_feed_id',$deleteArr);
                        ServiceFacebookFeed::model()->deleteAll($criteriaForDelete);
                    }                
                }              
            }  
        return true;
    }
}

/**
 * End of SFacebook Class
 */