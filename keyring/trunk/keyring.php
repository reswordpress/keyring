<?php
/*
Plugin Name: Keyring
Plugin URI: http://dentedreality.com.au/projects/wp-keyring/
Description: Keyring helps you manage your keys. It provides a generic, very hookable framework for connecting to remote systems and managing your access tokens, username/password combos etc for those services. On its own it doesn't do much, but it enables other plugins to do things that require authorization to act on your behalf.
Version: 1.0
Author: Beau Lebens
Author URI: http://dentedreality.com.au
*/

// Define this in your wp-config (and set to true) to enable debugging
defined( 'KEYRING__DEBUG_MODE' ) or define( 'KEYRING__DEBUG_MODE', false );

// The name of a class which extends Keyring_Store to handle storage/manipulation of tokens.
// Optionally define this in your wp-config.php or some other global config file.
defined( 'KEYRING__TOKEN_STORE' ) or define( 'KEYRING__TOKEN_STORE', 'Keyring_SingleStore' );

// Debug/messaging levels. Don't mess with these
define( 'KEYRING__DEBUG_NOTICE', 1 );
define( 'KEYRING__DEBUG_WARN',   2 );
define( 'KEYRING__DEBUG_ERROR',  3 );

// Indicates Keyring is installed/active so that other plugins can detect it
define( 'KEYRING__AVAILABLE', true );

/**
 * Core Keyring class that handles UI and the general flow of requesting access tokens etc
 * to manage access to remote services.
 */
class Keyring {
	var $registered_services = array();
	var $custom_ui = array();
	var $store     = false;
	var $errors    = array();
	var $messages  = array();
	
	function __construct() {
		// Hook up WP
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_head', array( $this, 'inline_css' ) );
	}
	
	static function &init() {
		static $instance = false;
		
		if ( !$instance ) {
			load_plugin_textdomain( 'keyring', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
			$instance = new Keyring;
		}
		
		return $instance;
	}
	
	function plugins_loaded() {
		// Load stores early so we can confirm they're loaded correctly
		require_once dirname( __FILE__ ) . '/store.php';
		do_action( 'keyring_load_token_stores' );
		if ( !defined( 'KEYRING__TOKEN_STORE' ) || !class_exists( KEYRING__TOKEN_STORE ) || !in_array( 'Keyring_Store', class_parents( KEYRING__TOKEN_STORE ) ) )
			wp_die( sprintf( __( 'Invalid KEYRING__TOKEN_STORE specified. Please make sure KEYRING__TOKEN_STORE is set to a valid classname for handling token storage in %s', 'keyring' ), __FILE__ ) );
		
		// Load base token and service definitions + core services
		require_once dirname( __FILE__ ) . '/token.php';
		require_once dirname( __FILE__ ) . '/service.php';
		
		// Once plugins are all loaded, we can load Keyring Services and set up our handlers
		add_action( 'init', array( 'Keyring', 'load_services' ) );
		add_action( 'init', array( 'Keyring', 'request_handlers' ), 100 );
	}
		
	/**
	 * Very simple, just triggers the loading of all services. After this, they should all
	 * be ready to go, and have attached any hooks they need, etc.
	 */
	function load_services() {
		do_action( 'keyring_load_services' );
	}
	
	function inline_css() {
		if ( empty( $_REQUEST['page'] ) || 'keyring' != $_REQUEST['page'] )
			return;
		
		?><style type="text/css">
		.wrap ul li {
			list-style-type: square;
			margin: .3em 0 .3em 2em;
		}
		</style><?php
	}
	
	function request_handlers() {
		if (
				( isset( $_REQUEST['page'] ) && 'keyring' == $_REQUEST['page'] )
			&&
				isset( $_REQUEST['action'] )
			&&
				(
					'request' == $_REQUEST['action'] // first part of oauth etc
				||
					'verify' == $_REQUEST['action'] // verify token/auths by making a secure connection usually
				||
					'created' == $_REQUEST['action'] // new connection was created, id available in $_REQUEST['id']
				)
			&&
				!empty( $_REQUEST['service'] )
			&&
				in_array( $_REQUEST['service'], array_keys( Keyring::get_registered_services() ) )
		) {
			do_action( "keyring_{$_REQUEST['service']}_{$_REQUEST['action']}", $_REQUEST );
		}
	}
	
	static function is_service( $service ) {
		if ( is_object( $service ) && is_a( $service, 'Keyring_Service' ) )
			return true;
		
		return false;
	}
	
	function admin_menu() {
		$hook = add_management_page( 'Keyring', 'Keyring', 'read', 'keyring', array( $this, 'admin_page' ), '' );
	}
	
	function has_errors() {
		return count( $this->errors );
	}
	
	function has_messages() {
		return count( $this->messages );
	}
	
	function admin_page_header( $active = false ) {
		// Output the actual heading + icon for the page
		echo '<div class="wrap">';
		screen_icon( 'ms-admin' );
		switch ( $active ) {
		case 'tokens' :
			echo '<h2>' . __( 'Keyring: Managed Keys', 'keyring' ) . ' <a href="' . Keyring_Util::admin_url( false, array( 'action' => 'services' ) ) . '" class="add-new-h2">' . __( 'Add New', 'keyring' ) . '</a></h2>';
			break;
		case 'services' :
			echo '<h2>' . __( 'Add New Connection', 'keyring' ) . '</h2>';
			echo '<p><a href="' . Keyring_Util::admin_url() . '">' . __( '&larr; Back', 'keyring' ) . '</a></p>';
			break;
		case 'error' :
			echo '<h2>' . __( 'Keyring Error!', 'keyring' ) . '</h2>';
			break;
		default :
			echo '<h2>' . __( 'Keyring', 'keyring' ) . '</h2>';
		}
		
		// Output any errors if we have them, then stop, and link back to home.
		$keyring = Keyring::init();
		if ( $keyring->has_errors() ) {
			$keyring->admin_page_header( 'error' );
			echo '<div id="keyring-admin-errors"><ul>';
			foreach ( $keyring->errors as $error ) {
				echo "<li>$error</li>";
			}
			echo '</ul></div>';
			echo '<p class="submit"><a href="' . Keyring_Util::admin_url( $_REQUEST['service'] ) . '" class="button-primary">' . __( 'Start Again', 'keyring' ) . '</a></p>';
			return;
		}
		
		// Output any messages as part of the UI (don't abort).
		if ( $keyring->has_messages() ) {
			echo '<div id="keyring-admin-messages"><ul>';
			foreach ( $keyring->messages as $message ) {
				echo "<li>$message</li>";
			}
			echo '</ul></div>';
		}
	}
	
	static function admin_page_footer() {
		echo '</div>'; // class="wrap"
	}
	
	function admin_page() {
		// Handle delete request. Will default back to "tokens" later
		if ( isset( $_REQUEST['action'] ) && 'delete' == $_REQUEST['action'] ) {
			$this->get_token_store()->delete( $_REQUEST['service'], (int) $_REQUEST['token'] );
			$this->message( __( 'That token has been deleted.', 'keyring' ) );
		}
		
		// Set up our defaults
		$service = '';
		if ( !empty( $_REQUEST['service'] ) )
			$service = $_REQUEST['service'];
		
		$action = 'tokens';
		if ( isset( $_REQUEST['action'] ) && in_array( $_REQUEST['action'], array( 'tokens', 'services', 'request', 'verify', 'manage' ) ) )
			$action = $_REQUEST['action'];
		
		// Custom UI optionally hooked in to handle things in this case. Trigger that action
		// and assume it handles everything, so bail out after that.
		if ( $this->has_custom_ui( $service, $action ) ) {
			do_action( "keyring_{$service}_{$action}_ui" );
			return;
		}
		
		// Nothing else has bailed, so it must be one of our default/core screens.
		switch ( $action ) {
		case 'tokens' :
			$this->admin_page_header( 'tokens' );
			$tokens = Keyring::get_all_tokens();
			if ( count( $tokens ) ) {
				echo '<ul>';
				foreach ( $tokens as $token ) {
					echo '<li><strong>' . esc_html( $token->get_service()->get_label() ) . '</strong> [<a href="' . Keyring_Util::admin_url( false, array( 'action' => 'delete', 'service' => $token->get_service()->get_name(), 'token' => $token->get_uniq_id() ) ) . '" title="' . __( 'Delete', 'keyring' ) . '">&times;</a>]<br />' . $token . '<br />' . print_r( $token->get_meta(), true ) . '</li>';
				}
				echo '</ul>';
			} else {
				echo '<p>' . sprintf( __( 'You haven\'t created any secure connections yet. <a href="%s">Create a connection</a>.', 'keyring' ), esc_url( Keyring_Util::admin_url( false, array( 'action' => 'services' ) ) ) ) . '</p>';
			}
			$this->admin_page_footer();
			break;
		case 'services' :
			$this->admin_page_header( 'services' );
			echo '<p>' . __( 'Click a service to create a new authorized connection:', 'keyring' ) . '</p>';
			$services = Keyring::get_registered_services();
			if ( count( $services ) ) {
				echo '<ul>';
				foreach ( $services as $service ) {
					echo '<li><a href="' . esc_url( Keyring_Util::admin_url( $service->get_name(), array( 'action' => 'request' ) ) ) . '">' . esc_html( $service->get_label() ) . '</a>';
					if ( has_action( 'keyring_' . $service->get_name() . '_manage_ui' ) )
						echo ' (<a href="' . esc_url( Keyring_Util::admin_url( $service->get_name(), array( 'action' => 'manage' ) ) ) . '">' . __( 'Manage', 'keyring' ) . '</a>)';
					echo '</li>';
				}
				echo '</ul>';
			}
			$this->admin_page_footer();
			break;
		}
	}
	
	static function has_custom_ui( $service, $action ) {
		return has_action( "keyring_{$service}_{$action}_ui" );
	}
	
	static function register_service( $service ) {
		$keyring = Keyring::init();
		if ( $keyring->is_service( $service ) ) {
			$keyring->registered_services[ $service->get_name() ] = $service;
		} else {
			return false;
		}
	}
	
	static function get_registered_services() {
		$keyring = Keyring::init();
		return $keyring->registered_services;
	}
	
	static function get_service_by_name( $name ) {
		$keyring = Keyring::init();
		if ( !isset( $keyring->registered_services[ $name ] ) )
			return null;
		
		return $keyring->registered_services[ $name ];
	}
	
	static function get_token_store() {
		$keyring = Keyring::init();
		
		if ( !$keyring->store ) {
			$store = KEYRING__TOKEN_STORE;
			$keyring->store = $store::init();
		}
		
		return $keyring->store;
	}
	
	static function get_all_tokens() {
		$keyring = Keyring::init();
		return $keyring->get_token_store()->get_all();
	}

	static function get_tokens( $service, $id = false ) {
		$store = Keyring::get_token_store();
		$token = $store->get_tokens( $service, $id );
		return $id && isset( $token[0] ) ? $token[0] : $token;
	}

	static function message( $str ) {
		$keyring = Keyring::init();
		$keyring->messages[] = $str;
	}
	
	static function error( $str ) {
		$keyring = Keyring::init();
		$keyring->errors[] = $str;
	}
	
	static function is_error( $err ) {
		return is_a( $str, 'Keyring_Error' );
	}
}

class Keyring_Util {
	static function debug( $str, $level = KEYRING__DEBUG_NOTICE ) {
		if ( !KEYRING__DEBUG_MODE )
			return;
		
		if ( is_object( $str ) || is_array( $str ) )
			$str = print_r( $str, true );
		
		switch ( $level ) {
		case KEYRING__DEBUG_WARN :
			echo "<div class='keyring-warning'>Keyring Warning: $str</div>";
			break;
		case KEYRING__DEBUG_ERROR :
			wp_die( '<h1>Keyring Error:</h1>' . '<p>' . $str . '</p>' );
			exit;
		}
		
		error_log( "Keyring: $str" );
	}
	
	/**
	 * Get a URL to the Keyring admin UI, works kinda like WP's admin_url()
	 *
	 * @param string $service Shortname of a specific service.
	 * @return URL to Keyring admin UI (main listing, or specific service verify process)
	 */
	static function admin_url( $service = false, $params = array() ) {
		if ( $service )
			$service = "&service=$service";
		if ( count( $params ) )
			foreach ( $params as $key => $val )
				$params[$key] = urlencode( $key ) . '=' . urlencode( $val );
		return admin_url( "tools.php?page=keyring$service&" . implode( '&', $params ) );
	}
	
	static function connect_to( $service, $cookie ) {
		// Redirect into Keyring's auth handler if a valid service is provided
		setcookie( $cookie, true );
		wp_safe_redirect( Keyring_Util::admin_url( $service, array( 'action' => 'request' ) ) );
		exit;
	} 
	
	static function token_select_box( $tokens, $name, $create = false ) {
		?><select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>">
		<?php if ( $create ) : ?>
			<option value="new"><?php _e( 'Create a new connection...', 'keyring' ); ?></option>
		<?php endif; ?>
		<?php foreach ( (array) $tokens as $token ) : ?>
			<option value="<?php echo $token->get_uniq_id(); ?>"><?php echo $token->get_display(); ?></option>
		<?php endforeach; ?>
		</select><?php
	}
	
	static function is_error( $obj ) {
		return is_a( $obj, 'Keyring_Error' ) ? true : false;
	}
}

class Keyring_Error extends WP_Error { }

add_action( 'plugins_loaded', array( 'Keyring', 'plugins_loaded' ) );
add_action( 'init', array( 'Keyring', 'init' ) );

