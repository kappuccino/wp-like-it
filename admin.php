<?php

// https://codex.wordpress.org/Creating_Options_Pages

class likeItAdmin{

	private $options = [];

	function __construct(){
		add_action('admin_menu', [$this, 'setup_menu']);
		add_action('admin_init', [$this, 'register_settings']);

		//add_action('admin_enqueue_scripts', [$this, 'enqueue']);

	}

	function enqueue(){

		$screen = get_current_screen();
		if(strpos($screen->id, 'polaris-setting-admin') === false) return;

		$root = MYTHEME.'/admin';

		wp_enqueue_style('polaris-admin', $root.'/css/css.css', []);
		wp_enqueue_script('polaris-admin', $root.'/js/js.js', ['jquery'], NULL, true);
	}

	function setup_menu(){

		add_submenu_page (
			'options-general.php', //$parent_slug,
			'Like It settings', //string $page_title,
			'Like It', //string $menu_title,
			'manage_options', //string $capability,
			'front-page-elements', //string $menu_slug,
			[$this, 'page_settings'] //callable $function = ''
		);
	}

	function page_settings(){
		// Check if the user is allowed to update options
		if(!current_user_can('manage_options')){
			wp_die('You do not have sufficient permissions to access this page.');
		}

		$this->options = get_option('likeit_options');

		?>
		<h1>Like It</h1>

		<div class="wrap">
			<form method="post" action="options.php"><?php
				settings_fields('likeit_options_group');
				do_settings_sections('likeit_setting_admin');
				submit_button();
			?></form>
		</div>

		<?php
	}

	function register_settings(){

		register_setting(
			'likeit_options_group',  // Option group
			'likeit_options',   // Option name
			[$this, 'sanitize']
		);

		add_settings_section(
			'likeit_setting_section',
			'Settings',
			[$this, 'print_section_info'], // Callback
			'likeit_setting_admin'
		);

		add_settings_field(
			'create_bp_activity',
			'Create a BP Activity',
			[$this, 'create_bp_activity'],
			'likeit_setting_admin',
			'likeit_setting_section'
		);

	}





	/**
	 * Sanitize each setting field as needed
	 *
	 * @param array $input Contains all settings fields as array keys
	 * @return array
	 */
	public function sanitize($input){

		$new_input = [];

		if(isset($input['create_bp_activity']))
			$new_input['create_bp_activity'] = boolval($input['create_bp_activity']);

		return $new_input;
	}

	/**
	 * Print the Section text
	 */
	public function print_section_info(){
	//	print 'Entrez vos réglages ci-dessous';
	}

	private function option($name){
		if(!isset($this->options[$name])) return false;
		return $this->options[$name];
	}

	private function description($str){
		echo '<p class="description">'.$str.'</p>';
	}




	



	public function create_bp_activity(){

		$checked = '';
		if($this->option('create_bp_activity')) $checked = 'checked="checked"';

		printf(
			'<input type="checkbox" name="likeit_options[create_bp_activity]" value="1" %s />',
			$checked
		);

		$this->description('When a user like something, create a buddy press activity with related ids');
	}





}

