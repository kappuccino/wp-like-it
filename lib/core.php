<?php
define('LIKE_IT_VERSION', '1.0');
define('LIKE_IT_DB_VERSION', '1.1');

class likeItCore{

	static $namespace = 'likeit';
	static $dbVersion = 'likeit_db_version';
	static $metaCount = '_like';
	static $tableName;
	private static $instance = null;
	private $options = [];


	function __construct(){
		global $wpdb;
		self::$tableName = $wpdb->prefix.'likeit';

		$this->hooks();

		$this->options = get_option('likeit_options');

		if(defined('DOING_AJAX') && DOING_AJAX){
			$this->makeAjax('wplikeit', [$this, 'ajaxLike']);
			$this->makeAjax('wpunlikeit', [$this, 'ajaxUnlike']);
		}
	}

	public static function instance(){
		if(is_null(self::$instance)) self::$instance = new likeItCore();
		return self::$instance;
	}


//-- Internal

	/**
	 * Triggered when the plugin is activated, create/update the database
	 */
	static function activated(){
		self::dbCheck();
	}

	/**
	 * Triggered when the plugin is desactivated
	 */
	static function desactivated(){
	}

	/**
	 * Create or Update the database tables structure
	 *
	 * @see https://codex.wordpress.org/Creating_Tables_with_Plugins
	 * @see http://wordpress.stackexchange.com/questions/144870/wordpress-update-plugin-hook-action-since-3-9
	 */
	static function dbCheck(){
		global $wpdb;

		// Check if the database structure version
		$version = get_option(self::$dbVersion);
		if($version == LIKE_IT_DB_VERSION) return;

		// Need to perform some patch on the db
		$table = self::$tableName;
		if ($wpdb->get_var("SHOW TABLE LIKE '{$table}'") != $table){
			$sql = "CREATE TABLE ".$table." (
				like_id bigint(20) NOT NULL AUTO_INCREMENT,
				what varchar(20) NOT NULL,
				id bigint(20) NOT NULL,
				user_id bigint(20) NOT NULL,
				time datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (like_id),
				UNIQUE id_what_user (id,what,user_id),
				KEY id (id),
				KEY user_id (user_id)
			) ".$wpdb->get_charset_collate();

			require_once ABSPATH.'wp-admin/includes/upgrade.php';
			dbDelta($sql);
		}

		// Auto loaded
		add_option(self::$dbVersion, LIKE_IT_DB_VERSION, '', true);
	}

	/**
	 * Triggered whent he plugin is uninstalled (clean db + meta)
	 */
	function uninstall(){
		global $wpdb;

		$wpdb->query("DROP TABLE IF EXISTS `".self::$tableName."`");

		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}postmeta WHERE meta_key=%s",
			self::$metaCount
		));

		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}usermeta WHERE meta_key=%s",
			self::$metaCount
		));

		delete_option(self::$dbVersion);
	}

	/**
	 * Define hook (when entities ares deleted, do some cleanup)
	 */
	function hooks(){
		add_action('deleted_post', [$this, 'postDeleted'], 10);
		add_action('deleted_user', [$this, 'userDeleted'], 10);
		add_action('bp_before_activity_delete', [$this, 'bpActivityDelete'], 10);
	}


//-- Ajax

	function makeAjax($action, $callable){
		add_action('wp_ajax_' . $action, $callable);
		add_action('wp_ajax_nopriv_' . $action, $callable);
	}

	function json($data){
		header("Content-Type: application/json");
		echo json_encode($data);
		exit;
	}

	function jsonError($err, $msg = NULL, Array $more = []){
		$ajax = ['error' => $err];
		if(!empty($msg)) $ajax['message'] = $msg;
		$ajax = $ajax + $more;

		header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
		$this->json($ajax);
	}

	function ajaxLike(){
		try{
			$payload = $this->like($_REQUEST['id'], $_REQUEST['what'], $_REQUEST['user']);
			$this->json($payload);
		} catch(Exception $e){
			$this->jsonError($e->getMessage());
		}
	}

	function ajaxUnlike(){
		try{
			$payload = $this->unlike($_REQUEST['id'], $_REQUEST['what'], $_REQUEST['user']);
			$this->json($payload);
		} catch(Exception $e){
			$this->jsonError($e->getMessage());
		}
	}

//-- Helper

	private function createBpActivity(){
		if(!$this->options['create_bp_activity']) return false;
		if(!function_exists('bp_is_active') && bp_is_active('activity')) return false;
		return true;
	}

//-- Like System

	/**
	 * Perform a like on something ($what=post), specify by $id
	 * if $user is not specify get the current user id
	 * $id, $what, $user must be set in one way or another
	 *
	 * Buddy Press:
	 * @see https://codex.buddypress.org/developer/function-examples/bp_activity_add/
	 *
	 * @param        $id
	 * @param string $what
	 * @param null   $user
	 * @throws Exception
	 * @return array
	 */
	function like($id, $what='post', $user=NULL){
		global $wpdb;

		if(empty($id)) $id = get_the_ID();
		if(empty($id)) throw new Exception('id is empty');

		if(empty($user)) $user = get_current_user_id();
		if(empty($user)) throw new Exception('user is empty');

		if(empty($what)) $what = 'post';

		$query = $wpdb->prepare(
			"INSERT IGNORE INTO ".self::$tableName." SET id=%d, user_id=%d, what=%s, time=NOW()",
			$id, $user, $what
		);

		$wpdb->query($query);
		$like_id = $wpdb->insert_id;

		// Create a BuddyPress Activity
		if($this->createBpActivity() && $like_id > 0){

			$type = 'likeit_post';
			if($what == 'user') $type = 'likeit_user';

			$the_user = get_user_by('id', $user);
			$user_html = '<a href="'.get_author_posts_url($user).'">'.$the_user->data->display_name.'</a>';

			$link_html = '';
			if($what == 'post'){
				$link_html = '<a href="'.get_the_permalink($id).'">'.get_the_title($id).'</a>';
			}else
			if($what == 'user'){
				$the_user = get_user_by('id', $id);
				$link_html = '<a href="'.get_author_posts_url($user).'">'.$the_user->data->display_name.'</a>';
			}

			$params = [
				'action' => sprintf('%s likes %s', $user_html, $link_html),
				'component' => 'likeit',
				'type' => $type,
				'user_id' => $user,
				'item_id' => $id
			];

			bp_activity_add($params);
		}

		$count = self::refreshCount($id, $what);

		return [
			'id' => intval($id),
			'what' => $what,
			'user' => intval($user),
			'count' => $count,
			'liked' => true
		];
	}

	/**
	 * Remove a like (undo), acts like like() but delete the like
	 *
	 * @param        $id
	 * @param string $what
	 * @param null   $user
	 * @return array
	 * @throws Exception
	 */
	function unlike($id, $what='post', $user=NULL){

		if(empty($id)) $id = get_the_ID();
		if(empty($id)) throw new Exception('id is empty');

		if(empty($user)) $user = get_current_user_id();
		if(empty($user)) throw new Exception('user is empty');

		if(empty($what)) $what = 'post';

		$count = $this->undo($id, $what, $user);

		// Remove any BuddyPress Activity
		if(function_exists('bp_is_active') && bp_is_active('activity')){

			$type = 'likeit_post';
			if($what == 'user') $type = 'likeit_user';

			$params = [
				'component' => 'likeit',
				'type' => $type,
				'user_id' => $user,
				'item_id' => $id
			];

			bp_activity_delete($params);
		}

		return [
			'id' => intval($id),
			'what' => $what,
			'user' => intval($user),
			'count' => $count,
			'liked' => false
		];
	}

	private function undo($id, $what, $user){
		global $wpdb;

		$wpdb->delete(
			self::$tableName,
			[
				'id' => $id,
				'user_id' => $user,
				'what' =>$what
			],
			['%d', '%d', '%s']
		);

		return self::refreshCount($id, $what);
	}

	/**
	 * Get the like count from db and update the meta, return the count
	 *
	 * @param        $id
	 * @param string $what
	 * @return int
	 */
	static function refreshCount($id, $what='post'){
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT COUNT(like_id) FROM ".self::$tableName." WHERE id=%d AND what=%s",
			$id, $what
		);

		$count = intval($wpdb->get_var($query));

		if($what == 'post') update_post_meta($id, self::$metaCount, $count);
		if($what == 'user') update_post_meta($id, self::$metaCount, $count);

		return $count;
	}

	/**
	 * Get the like count from meta data
	 *
	 * @param        $id
	 * @param string $what
	 * @return int
	 */
	static function getCount($id, $what='post'){
		$count = 0;

		if($what == 'post') $count = get_post_meta($id, self::$metaCount, true);
		if($what == 'user') $count = get_user_meta($id, self::$metaCount, true);

		return intval($count);
	}

	/**
	 * Get the like for $what from its $id. return an array with data about the like
	 *
	 * @param        $id
	 * @param string $what
	 * @param null   $user
	 * @return array
	 */
	function getLike($id, $what='post', $user=NULL){

		global $wpdb;

		if(empty($id)) $id = get_the_ID();
		if(empty($user)) $user = get_current_user_id();

		$payload = [
			'id' => $id,
			'user' => $user,
			'what' => $what,
			'liked' => false,
			'count' => 0
		];

		// If this user has liked this entity before ?
		if(!empty($id) && !empty($what) && !empty($user)){

			$query = $wpdb->prepare(
				"SELECT like_id FROM ".self::$tableName." WHERE id=%d AND user_id=%d AND what=%s",
				$id, $user, $what
			);

			$like_id = $wpdb->get_var($query);
			if(!empty($like_id)) $payload['liked'] = true;
		}

		// Get like count from this entity
		$count = self::getCount($id, $what);
		if(!empty($count)) $payload['count'] = intval($count);

		return $payload;
	}


//-- Post


	/**
	 * When a post is removed, clean some meta
	 *
	 * @param $post_id
	 */
	function postDeleted($post_id){
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}usermeta WHERE post_id=%s",
				$post_id
		));
	}


//-- User

	/**
	 * Return the number of like a user has done
	 *
	 * @param $user_id
	 * @throws Exception
	 * @return int
	 */
	static function getUserCount($user_id){
		global $wpdb;

		if(empty($user_id)) throw new Exception('user_id is empty');

		$query = $wpdb->prepare(
			"SELECT COUNT(*) FROM ".self::$tableName." WHERE user_id=%d",
			$user_id
		);

		$count = $wpdb->get_var($query);

		return intval($count);
	}

	/**
	 * When a user is deleted, clean some meta
	 * @param $user_id
	 */
	function userDeleted($user_id){
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}usermeta WHERE user_id=%s",
			$user_id
		));

	}


//-- Activity

	/**
	 * Just before an activity is removed, remove the like
	 *
	 * @param $activity
	 */
	function bpActivityDelete($activity){

		$activities = bp_activity_get([
			'spam' => 'all',
			'in' => $activity['id']
		]);

		$activities = $activities['activities'];

		if(empty($activities)) return;
		$activity = $activities[0];

		$what = 'post';
		if($activity->type == 'likeit_user') $what = 'user';

		// Remove the like
		$this->undo($activity->item_id, $what, $activity->user_id);
	}
}

// Singleton
function likeit(){
	return likeItCore::instance();
}
