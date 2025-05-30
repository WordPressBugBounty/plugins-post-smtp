<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}
/**
 * Postman execution begins here:
 * - the default Postman transports are loaded
 * - the wp_mail function is overloaded, whether or not Postman has been properly configured
 * - the custom post types are created, in case they are needed for the WordPress importer
 * - the database upgrade is run, if there is a version mismatch
 * - the shortcode is created
 * - the admin screens are loaded, the Message Handler created, if the current user can manage Postman
 * - on activation/deactivation, the custom capability is added to/removed from the administrator role
 * - a custom str_getcsv function is added to the global namespace, if it is missing
 *
 * @author jasonhendriks
 * @copyright Jan 16, 2015
 */
class Postman {

	const ADMINISTRATOR_ROLE_NAME = 'administrator';
	const MANAGE_POSTMAN_CAPABILITY_NAME = 'manage_postman_smtp';
	const MANAGE_POSTMAN_CAPABILITY_LOGS = 'manage_postman_logs';

	/**
	 * Use the text domain directly instead of this constant, as it
	 * causes issues with https://translate.wordpress.org.
	 *
	 * @deprecated
	 * @see https://github.com/yehudah/Post-SMTP/issues/1#issuecomment-421940923
	 */
	const TEXT_DOMAIN = 'post-smtp';

	private $logger;
	private $messageHandler;
	private $wpMailBinder;
	private $pluginData;
	private $rootPluginFilenameAndPath;

	public static $rootPlugin;

	/**
	 * The constructor
	 *
	 * @param mixed $rootPluginFilenameAndPath
	 *        	- the __FILE__ of the caller
	 */
	public function __construct( $rootPluginFilenameAndPath, $version ) {
		assert( ! empty( $rootPluginFilenameAndPath ) );
		assert( ! empty( $version ) );
		$this->rootPluginFilenameAndPath = $rootPluginFilenameAndPath;
		self::$rootPlugin = $rootPluginFilenameAndPath;
		
		require_once POST_SMTP_PATH . '/Postman/Postman-Suggest-Pro/PostmanPromotionManager.php';
		//Load helper functions file :D
		require_once POST_SMTP_PATH . '/includes/postman-functions.php';

		// load the dependencies
		require_once 'PostmanOptions.php';
		require_once 'PostmanState.php';
		require_once 'PostmanLogger.php';
		require_once 'PostmanUtils.php';
		require_once 'Postman-Mail/PostmanTransportRegistry.php';
		require_once 'Postman-Mail/PostmanDefaultModuleTransport.php';
		require_once 'Postman-Mail/PostmanSmtpModuleTransport.php';
		require_once 'Postman-Mail/PostmanGmailApiModuleTransport.php';
		require_once 'Postman-Mail/PostmanMandrillTransport.php';
		require_once 'Postman-Mail/PostmanSendGridTransport.php';
		require_once 'Postman-Mail/PostmanMailgunTransport.php';
        require_once 'Postman-Mail/PostmanSendinblueTransport.php';
		require_once 'Postman-Mail/PostmanMailjetTransport.php';
		require_once 'Postman-Mail/PostmanSendpulseTransport.php';
		require_once 'Postman-Suggest-Pro/PostmanSuggestProSocket.php';
        require_once 'Postman-Mail/PostmanPostmarkTransport.php';
        require_once 'Postman-Mail/PostmanSparkPostTransport.php';
        require_once 'Postman-Mail/PostmanElasticEmailTransport.php';
        require_once 'Postman-Mail/PostmanSmtp2GoTransport.php';
        require_once 'PostmanOAuthToken.php';
		require_once 'PostmanWpMailBinder.php';
		require_once 'PostmanConfigTextHelper.php';
		require_once 'Postman-Email-Log/PostmanEmailLogPostType.php';
		require_once 'Postman-Mail/PostmanMyMailConnector.php';
		require_once 'Postman-Mail/PostmanContactForm7.php';
		require_once 'Phpmailer/PostsmtpMailer.php';
		//require_once 'Postman-Mail/PostmanWooCommerce.php';
		require_once 'Postman-Mail/Services/PostmanServiceRequest.php';

		//New Wizard
		require_once 'Wizard/NewWizard.php';
		//load MainWP Child Files
		require_once 'Extensions/MainWP-Child/mainwp-child.php';

		//Mobile Application
		require_once 'Mobile/mobile.php';

		//Email Reporting
		require_once 'Postman-Email-Health-Report/PostmanEmailReporting.php';
		require_once 'Postman-Email-Health-Report/PostmanEmailReportSending.php';

        // New Dashboard
		require_once 'Dashboard/NewDashboard.php';

		// Email Tester
		require_once 'Postman-Mail-Tester/PostmanEmailTester.php';


		// get plugin metadata - alternative to get_plugin_data
		$this->pluginData = array(
				'name' => 'Post SMTP',
				'version' => $version,
		);

		// register the plugin metadata filter (part of the Postman API)
		add_filter( 'postman_get_plugin_metadata', array(
				$this,
				'getPluginMetaData',
		) );

		// create an instance of the logger
		$this->logger = new PostmanLogger( get_class( $this ) );
		if ( $this->logger->isDebug() ) {
			$this->logger->debug( sprintf( '%1$s v%2$s starting', $this->pluginData ['name'], $this->pluginData ['version'] ) );
		}

		if ( isset( $_REQUEST ['page'] ) && $this->logger->isTrace() ) {
			$this->logger->trace( 'Current page: ' . sanitize_text_field($_REQUEST ['page']) );
		}

		// register the email transports

        // store an instance of the WpMailBinder
        $this->wpMailBinder = PostmanWpMailBinder::getInstance();

        if( apply_filters( 'post_smtp_declare_wp_mail', true ) ) {

			$mailer = PostmanOptions::getInstance()->getSmtpMailer();
			$this->logger->trace( 'SMTP Mailer: ' . $mailer );

			if ( $mailer && $mailer !== 'phpmailer') {

				// bind to wp_mail - this has to happen before the "init" action
				// this design allows other plugins to register a Postman transport and call bind()
				// bind may be called more than once
				$this->wpMailBinder->bind();
			} else {
				PostmanWpMailBinder::getInstance()->bound = true;
			}

		}

		// registers the custom post type for all callers
		PostmanEmailLogPostType::automaticallyCreatePostType();

		// run the DatastoreUpgrader any time there is a version mismatch
		if ( PostmanState::getInstance()->getVersion() != $this->pluginData ['version'] ) {
			// manually trigger the activation hook
			if ( $this->logger->isInfo() ) {
				$this->logger->info( sprintf( 'Upgrading datastore from version %s to %s', PostmanState::getInstance()->getVersion(), $this->pluginData ['version'] ) );
			}
			require_once 'PostmanInstaller.php';
			$upgrader = new PostmanInstaller();
			$upgrader->activatePostman();
		}

		// MyMail integration
		new PostmanMyMailConnector( $rootPluginFilenameAndPath );

		// WooCommerce Integration
		//new PostmanWoocommerce();

		// register the shortcode handler on the add_shortcode event
		add_shortcode( 'postman-version', array(
				$this,
				'version_shortcode',
		) );

		// hook on the plugins_loaded event
		add_action( 'init', array(
			$this,
			'on_init',
		), 0 );

		//Conflicting with backupbuddy, will be removed soon 
        //add_filter( 'extra_plugin_headers', [ $this, 'add_extension_headers' ] );

		// hook on the wp_loaded event
		add_action( 'wp_loaded', array(
				$this,
				'on_wp_loaded',
		) );

		// hook on the acivation event
		register_activation_hook( $rootPluginFilenameAndPath, array(
				$this,
				'on_activation',
		) );

		// hook on the deactivation event
		register_deactivation_hook( $rootPluginFilenameAndPath, array(
				$this,
				'on_deactivation',
		) );

	}

    function add_extension_headers($headers) {
        $headers[] = 'Class';
        $headers[] = 'Slug';

        return $headers;
    }

	/**
	 * Functions to execute on the plugins_loaded event
	 *
	 * "After active plugins and pluggable functions are loaded"
	 * ref: http://codex.wordpress.org/Plugin_API/Action_Reference#Actions_Run_During_a_Typical_Request
	 */
	public function on_init() {

		// register the email transports
		$this->registerTransports( $this->rootPluginFilenameAndPath );

		// register the setup_admin function on plugins_loaded because we need to call
		// current_user_can to verify the capability of the current user
		if ( PostmanUtils::isAdmin() && is_admin() ) {
			$this->setup_admin();
		}
		
	}

	/**
	 * Functions to execute on the wp_loaded event
	 *
	 * "After WordPress is fully loaded"
	 * ref: http://codex.wordpress.org/Plugin_API/Action_Reference#Actions_Run_During_a_Typical_Request
	 */
	public function on_wp_loaded() {
		// register the check for configuration errors on the wp_loaded hook,
		// because we want it to run after the OAuth Grant Code check on the init hook
		$this->check_for_configuration_errors();
	}

	/**
	 * Functions to execute on the register_activation_hook
	 * ref: https://codex.wordpress.org/Function_Reference/register_activation_hook
	 */
	public function on_activation() {

		if ( $this->logger->isInfo() ) {
			$this->logger->info( 'Activating plugin' );
		}
		require_once 'PostmanInstaller.php';
		$upgrader = new PostmanInstaller();
		$upgrader->activatePostman();
	}

	/**
	 * Functions to execute on the register_deactivation_hook
	 * ref: https://codex.wordpress.org/Function_Reference/register_deactivation_hook
	 */
	public function on_deactivation() {
		if ( $this->logger->isInfo() ) {
			$this->logger->info( 'Deactivating plugin' );
		}
		require_once 'PostmanInstaller.php';
		$upgrader = new PostmanInstaller();
		$upgrader->deactivatePostman();
	}

	/**
	 * If the user is on the WordPress Admin page, creates the Admin screens
	 */
	public function setup_admin() {
		$this->logger->debug( 'Admin start-up sequence' );

		$options = PostmanOptions::getInstance();
		$authToken = PostmanOAuthToken::getInstance();
		$rootPluginFilenameAndPath = $this->rootPluginFilenameAndPath;

		// load the dependencies
		require_once 'PostmanMessageHandler.php';
		require_once 'PostmanAdminController.php';
		require_once 'Postman-Controller/PostmanWelcomeController.php';
		require_once 'Postman-Controller/PostmanDashboardWidgetController.php';
		require_once 'Postman-Controller/PostmanAdminPointer.php';
		require_once 'Postman-Email-Log/PostmanEmailLogController.php';
		require_once 'Postman-Connectivity-Test/PostmanConnectivityTestController.php';
		require_once 'Postman-Configuration/PostmanConfigurationController.php';
		require_once 'Postman-Send-Test-Email/PostmanSendTestEmailController.php';
		require_once 'Postman-Diagnostic-Test/PostmanDiagnosticTestController.php';

		// create and store an instance of the MessageHandler
		$this->messageHandler = new PostmanMessageHandler();

		// create the Admin Controllers
		new PostmanWelcomeController( $rootPluginFilenameAndPath );
		new PostmanDashboardWidgetController( $rootPluginFilenameAndPath, $options, $authToken, $this->wpMailBinder );
		new PostmanAdminController( $rootPluginFilenameAndPath, $options, $authToken, $this->messageHandler, $this->wpMailBinder );
		new PostmanEmailLogController( $rootPluginFilenameAndPath );
		new PostmanConnectivityTestController( $rootPluginFilenameAndPath );
		new PostmanConfigurationController( $rootPluginFilenameAndPath );
		new PostmanSendTestEmailController( $rootPluginFilenameAndPath );
		new PostmanDiagnosticTestController( $rootPluginFilenameAndPath );

		// register the Postman signature (only if we're on a postman admin screen) on the in_admin_footer event
		if ( PostmanUtils::isCurrentPagePostmanAdmin() ) {
			add_action( 'in_admin_footer', array(
					$this,
					'print_signature',
			) );
		}
	}

	/**
	 * Check for configuration errors and displays messages to the user
	 */
	public function check_for_configuration_errors() {
		$options = PostmanOptions::getInstance();
		$authToken = PostmanOAuthToken::getInstance();

		// did Postman fail binding to wp_mail()?
		if ( $this->wpMailBinder->isUnboundDueToException() ) {
			// this message gets printed on ANY WordPress admin page, as it's a fatal error that
			// may occur just by activating a new plugin
			// log the fatal message
			$this->logger->fatal( 'Postman: wp_mail has been declared by another plugin or theme, so you won\'t be able to use Postman until the conflict is resolved.' );

			if ( PostmanUtils::isAdmin() && is_admin() ) {
				// on any admin pages, show this error message
				// I noticed the wpMandrill and SendGrid plugins have the exact same error message here
				// I've adopted their error message as well, for shits and giggles .... :D
				$reflFunc = new ReflectionFunction( 'wp_mail' );

				$message = __( 'Postman: wp_mail has been declared by another plugin or theme, so you won\'t be able to use Postman until the conflict is resolved.', 'post-smtp' );

				$plugin_full_path = $reflFunc->getFileName();

				if ( strpos( $plugin_full_path, 'plugins' ) !== false ) {

					require_once ABSPATH . '/wp-admin/includes/plugin.php';

					preg_match( '/([a-z]+\/[a-z]+\.php)$/', $plugin_full_path, $output_array );

					$plugin_file = $output_array[1];
					$plugin_data = get_plugin_data( $plugin_full_path );

					$deactivate_url = '<a href="' . wp_nonce_url( 'plugins.php?action=deactivate&amp;plugin=' . urlencode( $plugin_file ) . '&amp;plugin_status=active&amp;paged=1&amp;s=deactivate-plugin_' . $plugin_file ) . '" aria-label="' . esc_attr( sprintf( _x( 'Deactivate %s', 'plugin' ), $plugin_data['Name'] ) ) . '">' . __( 'Deactivate' ) . '</a><br>';
					$message .= '<br><strong>Plugin Name:</strong> ' . $plugin_data['Name'];
					$message .= '<br>' . $deactivate_url;
				}

				$message .= '<br><strong>More info that may help</strong> - ' . $reflFunc->getFileName() . ':' . $reflFunc->getStartLine();

				// PHPmailer Recommandation
				ob_start();
                Postman::getMailerTypeRecommend();
                $message .= ob_get_clean();

				$this->messageHandler->addError( $message );
			}
		} else {
			$transport = PostmanTransportRegistry::getInstance()->getCurrentTransport();
			$scribe = $transport->getScribe();

			$virgin = $options->isNew();
			if ( ! $transport->isConfiguredAndReady() ) {
				// if the configuration is broken, and the user has started to configure the plugin
				// show this error message
				$messages = $transport->getConfigurationMessages();
				foreach ( $messages as $message ) {
					if ( $message ) {
						// log the warning message
						$this->logger->warn( sprintf( '%s Transport has a configuration problem: %s', $transport->getName(), $message ) );

						if ( PostmanUtils::isAdmin() && PostmanUtils::isCurrentPagePostmanAdmin() ) {
							// on pages that are Postman admin pages only, show this error message
							$this->messageHandler->addError( $message );
						}
					}
				}
			}

			// on pages that are NOT Postman admin pages only, show this error message
			if ( PostmanUtils::isAdmin() && ! PostmanUtils::isCurrentPagePostmanAdmin() && ! $transport->isConfiguredAndReady() ) {
				// on pages that are *NOT* Postman admin pages only....
				// if the configuration is broken show this error message
				add_action( 'admin_notices', array(
						$this,
						'display_configuration_required_warning',
				) );
			}
		}
	}

	public static function getMailerTypeRecommend() {
	    ?>
        <div>
            <p style="font-size: 18px; font-weight: bold;">Please notice</p>
            <p style="font-size: 14px; line-height: 1.7;">
                <?php _e('Post SMTP v2 includes and new feature called: <b>Mailer Type</b>.', 'post-smtp' ); ?><br>
                <?php _e('I recommend to change it and <strong>TEST</strong> Post SMTP with the value <code>PHPMailer</code>.', 'post-smtp' ); ?><br>
                <?php _e('<strong>ONLY</strong> if the default mailer type is not working for you.', 'post-smtp' ); ?><br>
                <a target="_blank" href="<?php echo POST_SMTP_ASSETS; ?>images/gif/mailer-type.gif">
                    <div>
                        <img width="300" src="<?php echo POST_SMTP_ASSETS; ?>images/gif/mailer-type.gif" alt="how to set mailer type">
                        <figcaption><?php _e('click to enlarge image.', 'post-smtp' ); ?></figcaption>
                    </div>
                </a>
            </p>
        </div>
        <?php
    }

	/**
	 * Returns the plugin version number and name
	 * Part of the Postman API
	 *
	 * @return multitype:unknown NULL
	 */
	public function getPluginMetaData() {
		// get plugin metadata
		return $this->pluginData;
	}

	/**
	 * This is the general message that Postman requires configuration, to warn users who think
	 * the plugin is ready-to-go as soon as it is activated.
	 * This message only goes away once the plugin is configured.
	 */
	public function display_configuration_required_warning() {
		if ( PostmanUtils::isAdmin() ) {
			if ( $this->logger->isDebug() ) {
				$this->logger->debug( 'Displaying configuration required warning' );
			}
			$msg = PostmanTransportRegistry::getInstance()->getReadyMessage();
			$message = sprintf( $msg['message'] );
			$goToSettings = sprintf( '<a href="%s">%s</a>', PostmanUtils::getSettingsPageUrl(), __( 'Settings', 'post-smtp' ) );
			$goToEmailLog = sprintf( '%s', _x( 'Email Log', 'The log of Emails that have been delivered', 'post-smtp' ) );
			if ( PostmanOptions::getInstance()->isMailLoggingEnabled() ) {
				$goToEmailLog = sprintf( '<a href="%s">%s</a>', PostmanUtils::getEmailLogPageUrl(), $goToEmailLog );
			}
			$message .= (sprintf( ' %s | %s', $goToEmailLog, $goToSettings ));
			$message .= '<input type="hidden" name="security" class="security" value="' . wp_create_nonce('postsmtp') . '">';

			$hide = get_option('postman_release_version' );

			if ( $msg['error'] == true && ! $hide ) {
				$this->messageHandler->printMessage( $message, 'postman-not-configured-notice notice notice-error is-dismissible' );
			}
		}
	}

	/**
	 * Register the email transports.
	 *
	 * The Gmail API used to be a separate plugin which was registered when that plugin
	 * was loaded. But now both the SMTP, Gmail API and other transports are registered here.
	 * @since 2.0.25 require `PostmanAdminController.php` if not exists.
	 * @param mixed $pluginData
	 */
	private function registerTransports( $rootPluginFilenameAndPath ) {

		if( !class_exists( 'PostmanAdminController' ) ) {
			require_once 'PostmanAdminController.php';
		}

	    $postman_transport_registry = PostmanTransportRegistry::getInstance();

        $postman_transport_registry->registerTransport( new PostmanDefaultModuleTransport( $rootPluginFilenameAndPath ) );
        $postman_transport_registry->registerTransport( new PostmanSmtpModuleTransport( $rootPluginFilenameAndPath ) );
        $postman_transport_registry->registerTransport( new PostmanGmailApiModuleTransport( $rootPluginFilenameAndPath ) );
        $postman_transport_registry->registerTransport( new PostmanMandrillTransport( $rootPluginFilenameAndPath ) );
        $postman_transport_registry->registerTransport( new PostmanSendGridTransport( $rootPluginFilenameAndPath ) );
        $postman_transport_registry->registerTransport( new PostmanMailgunTransport( $rootPluginFilenameAndPath ) );
        $postman_transport_registry->registerTransport( new PostmanSendinblueTransport( $rootPluginFilenameAndPath ) );
		$postman_transport_registry->registerTransport( new PostmanMailjetTransport( $rootPluginFilenameAndPath ) );
		$postman_transport_registry->registerTransport( new PostmanSendpulseTransport( $rootPluginFilenameAndPath ) );
        $postman_transport_registry->registerTransport( new PostmanPostmarkTransport( $rootPluginFilenameAndPath ) );
        $postman_transport_registry->registerTransport( new PostmanSparkPostTransport( $rootPluginFilenameAndPath ) );
        $postman_transport_registry->registerTransport( new PostmanElasticEmailTransport( $rootPluginFilenameAndPath ) );
        $postman_transport_registry->registerTransport( new PostmanSmtp2GoTransport( $rootPluginFilenameAndPath ) );

		do_action( 'postsmtp_register_transport', $postman_transport_registry );
	}

	/**
	 * Print the Postman signature on the bottom of the page
	 *
	 * http://striderweb.com/nerdaphernalia/2008/06/give-your-wordpress-plugin-credit/
	 */
	function print_signature() {
		printf( '<a href="https://wordpress.org/plugins/post-smtp/">%s</a> %s<br/>', $this->pluginData ['name'], $this->pluginData ['version'] );
	}

	/**
	 * Shortcode to return the current plugin version.
	 *
	 * From http://code.garyjones.co.uk/get-wordpress-plugin-version/
	 *
	 * @return string Plugin version
	 */
	function version_shortcode() {
		return $this->pluginData ['version'];
	}
}

if ( ! function_exists( 'str_getcsv' ) ) {
	/**
	 * PHP version less than 5.3 don't have str_getcsv natively.
	 *
	 * @param mixed $string
	 * @return multitype:
	 */
	function str_getcsv( $string ) {
		$logger = new PostmanLogger( 'postman-common-functions' );
		if ( $logger->isDebug() ) {
			$logger->debug( 'Using custom str_getcsv' );
		}
		return PostmanUtils::postman_strgetcsv_impl( $string );
	}
}