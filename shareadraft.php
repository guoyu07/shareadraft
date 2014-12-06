<?php
/**
 * Plugin Name:  Share a Draft
 * Plugin URI:   http://wordpress.org/extend/plugins/shareadraft/
 * Description:  Let your friends preview one of your drafts, without giving them permissions to edit posts in your blog.
 * Author:       Nikolay Bachiyski, ERic Mann
 * Version:      1.5
 * Author URI:   http://nikolay.bg/
 * Text Domain:  shareadraft
 * Generated At: www.wp-fun.co.uk;
 */

if ( ! class_exists( 'ShareADraft' ) ):
class ShareADraft	{
	var $admin_options_name = "ShareADraft_options";

	/**
	 * @var array Admin options collection
	 */
	protected $admin_options = array();

	/**
	 * @var array User-specific options collection
	 */
	protected $user_options = array();

	/**
	 * @var WP_Post|null Shared post object
	 */
	protected $shared_post = null;

	function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Initialize the plugin, loading relevant actions and textdomains
	 */
	function init() {
		$user_id = get_current_user_id();

		add_action( 'admin_menu',    array( $this, 'add_admin_pages' )         );
		add_filter( 'the_posts',     array( $this, 'the_posts_intercept' )     );
		add_filter( 'posts_results', array( $this, 'posts_results_intercept' ) );

		$this->admin_options = $this->get_admin_options();
		$this->admin_options = $this->clear_expired( $this->admin_options );
		$this->user_options  = ( $user_id !== 0 && isset( $this->admin_options[ $user_id ] ) ) ? $this->admin_options[ $user_id ] : array();

		$this->save_admin_options();
		load_plugin_textdomain( 'shareadraft', WP_PLUGIN_DIR . '/shareadraft/languages' );

		if ( isset( $_GET['page'] ) && $_GET['page'] === plugin_basename( __FILE__ ) ) {
			$this->admin_page_init();
		}
	}

	/**
	 * Invoke admin scripts and styles
	 *
	 * @TODO Use real enqueues on the admin page
	 */
	function admin_page_init() {
		wp_enqueue_script( 'jquery' );
		add_action( 'admin_head', array( $this, 'print_admin_css' ) );
		add_action( 'admin_head', array( $this, 'print_admin_js' )  );
	}

	/**
	 * Fetch options from the database.
	 *
	 * @return array
	 */
	function get_admin_options() {
		$saved_options = get_option( $this->admin_options_name );

		return is_array( $saved_options ) ? $saved_options : array();
	}

	/**
	 * Update user options in the database for the current user
	 */
	function save_admin_options() {
		$user_id = get_current_user_id();

		if ( $user_id !== 0 ) {
			$this->admin_options[ $user_id ] = $this->user_options;
		}

		update_option( $this->admin_options_name, $this->admin_options );
	}

	/**
	 * Clear out any options from the system as needed
	 *
	 * @param array $all_options
	 *
	 * @return array
	 */
	function clear_expired( $all_options ) {
		$all = array();
		foreach ( $all_options as $user_id => $options ) {
			$shared = array();
			if ( ! isset( $options['shared'] ) || ! is_array( $options['shared'] ) ) {
				continue;
			}
			foreach ( $options['shared'] as $share ) {
				if ( $share['expires'] < time() ) {
					continue;
				}
				$shared[] = $share;
			}
			$options['shared'] = $shared;
			$all[ $user_id ]   = $options;
		}

		return $all;
	}

	/**
	 * Add admin pages to the UI
	 */
	function add_admin_pages() {
		add_submenu_page( 'edit.php', __( 'Share a Draft', 'shareadraft' ), __( 'Share a Draft', 'shareadraft' ), 'edit_posts', __FILE__, array( $this, 'output_existing_menu_sub_admin_page' ) );
	}

	/**
	 * Calculate time to expiration based on a given array
	 *
	 * @param array $params
	 *
     * @return int
	 */
	function calculate_seconds( $params ) {
		$exp      = 60;
		$multiply = 60;

		// Allow the expiration to be overridden
		if ( isset( $params['expires'] ) && ( $e = intval( $params['expires'] ) ) ) {
			$exp = $e;
		}

		$multiples = array(
			's' => 1,
			'm' => MINUTE_IN_SECONDS,
			'h' => HOUR_IN_SECONDS,
			'd' => DAY_IN_SECONDS,
		);
		if ( isset( $params['measure'] ) && isset( $multiples[ $params['measure'] ] ) ) {
			$multiply = $multiples[ $params['measure'] ];
		}

		return $exp * $multiply;
	}

	/**
	 * Process post options
	 *
	 * @param array $params
	 *
	 * @return string
	 */
	function process_post_options( $params ) {
		if ( isset( $params['post_id'] ) ) {
			$post = get_post( $params['post_id'] );
			if ( null === $post ) {
				return __( 'There is no such post!', 'shareadraft' );
			}
			if ( 'publish' === get_post_status( $post ) ) {
				return __( 'The post is published!', 'shareadraft' );
			}

			$this->user_options['shared'][] = array(
				'id'      => $post->ID,
				'expires' => time() + $this->calculate_seconds( $params ),
				'key'     => uniqid( 'shareadraft' . $post->ID . '_' )
			);

			$this->save_admin_options();
		}

		// Empty return
		return '';
	}

	/**
	 * Process the removal of a shared post.
	 *
	 * @param array $params
	 *
	 * @return string
	 */
	function process_delete( $params ) {
		if ( ! isset( $params['key'] ) ||
		     ! isset( $this->user_options['shared'] ) ||
		     ! is_array( $this->user_options['shared'] )
		) {
			return '';
		}
		$shared = array();
		foreach ( $this->user_options['shared'] as $share ) {
			if ( $share['key'] == $params['key'] ) {
				continue;
			}
			$shared[] = $share;
		}
		$this->user_options['shared'] = $shared;
		$this->save_admin_options();

		// Empty return
		return '';
	}

	/**
	 * Process the extension of an expiration date.
	 *
	 * @param array $params
	 *
	 * @return string
	 */
	function process_extend( $params ) {
		if ( ! isset( $params['key'] ) ||
		     ! isset( $this->user_options['shared'] ) ||
		     ! is_array( $this->user_options['shared'] )
		) {
			return '';
		}
		$shared = array();
		foreach ( $this->user_options['shared'] as $share ) {
			if ( $share['key'] == $params['key'] ) {
				$share['expires'] += $this->calculate_seconds( $params );
			}
			$shared[] = $share;
		}
		$this->user_options['shared'] = $shared;
		$this->save_admin_options();

		// Empty return
		return '';
	}

	/**
	 * Get available drafts for the current user.
	 *
	 * @return array
	 */
	function get_drafts() {
		$user_id = get_current_user_id();

		$my_drafts = get_users_drafts( $user_id );
		$my_scheduled = $this->get_users_future( $user_id );

		return array(
			array(
				__( 'Your Drafts:', 'shareadraft' ),
				count( $my_drafts ),
				$my_drafts,
			),
			array(
				__( 'Your Scheduled Posts:', 'shareadraft' ),
				count( $my_scheduled ),
				$my_scheduled,
			),
		);
	}

	/**
	 * Get future posts for a user.
	 *
	 * @param int $user_id
	 *
     * @return mixed
	 */
	function get_users_future( $user_id ) {
		global $wpdb;
		$query = $wpdb->prepare( "SELECT ID, post_title FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'future' AND post_author = %d ORDER BY post_modified DESC", $user_id );

		return $wpdb->get_results( $query );
	}

	/**
	 * Get shared posts for a user.
	 *
	 * @return array
	 */
	function get_shared() {
		if ( ! isset( $this->user_options['shared'] ) || ! is_array( $this->user_options['shared'] ) ) {
			return array();
		}

		return $this->user_options['shared'];
	}

	/**
	 * Display a friendly timestamp
	 *
	 * @param int $s Time in seconds
	 *
	 * @return string
	 */
	function friendly_delta( $s ) {
		$m      = (int) ( $s / 60 );
		$free_s = $s - $m * 60;
		$h      = (int) ( $s / 3600 );
		$free_m = (int) ( ( $s - $h * 3600 ) / 60 );
		$d      = (int) ( $s / ( 24 * 3600 ) );
		$free_h = (int) ( ( $s - $d * ( 24 * 3600 ) ) / 3600 );
		if ( $m < 1 ) {
			$res = array( $s );
		} elseif ( $h < 1 ) {
			$res = array( $free_s, $m );
		} elseif ( $d < 1 ) {
			$res = array( $free_s, $free_m, $h );
		} else {
			$res = array( $free_s, $free_m, $free_h, $d );
		}
		$names = array();
		if ( isset( $res[0] ) ) {
			$names[] = sprintf( _n( '%d second', '%d seconds', $res[0], 'shareadraft' ), $res[0] );
		}
		if ( isset( $res[1] ) ) {
			$names[] = sprintf( _n( '%d minute', '%d minutes', $res[1], 'shareadraft' ), $res[1] );
		}
		if ( isset( $res[2] ) ) {
			$names[] = sprintf( _n( '%d hour', '%d hours', $res[2], 'shareadraft' ), $res[2] );
		}
		if ( isset( $res[3] ) ) {
			$names[] = sprintf( _n( '%d day', '%d days', $res[3], 'shareadraft' ), $res[3] );
		}

		return implode( ', ', array_reverse( $names ) );
	}

	/**
	 * Output the settings page
	 */
	function output_existing_menu_sub_admin_page() {
		if ( isset( $_POST['shareadraft_submit'] ) ) {
			$msg = $this->process_post_options( $_POST );
		} elseif ( isset( $_POST['action'] ) && $_POST['action'] == 'extend' ) {
			$msg = $this->process_extend( $_POST );
		} elseif ( isset( $_GET['action'] ) && $_GET['action'] == 'delete' ) {
			$msg = $this->process_delete( $_GET );
		}
		$drafts_struct = $this->get_drafts();
		?>
		<div class="wrap">
			<h2><?php esc_html_e( 'Share a Draft', 'shareadraft' ); ?></h2>
			<?php if ( ! empty( $msg ) ): ?>
				<div id="message" class="updated fade"><?php echo esc_html( $msg ); ?></div>
			<?php endif; ?>
			<h3><?php esc_html_e( 'Currently shared drafts', 'shareadraft' ); ?></h3>
			<table class="widefat">
				<thead>
				<tr>
					<th><?php esc_html_e( 'ID', 'shareadraft' ); ?></th>
					<th><?php esc_html_e( 'Title', 'shareadraft' ); ?></th>
					<th><?php esc_html_e( 'Link', 'shareadraft' ); ?></th>
					<th><?php esc_html_e( 'Expires after', 'shareadraft' ); ?></th>
					<th colspan="2" class="actions"><?php esc_html_e( 'Actions', 'shareadraft' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php
				$s = $this->get_shared();
				foreach ( $s as $share ):
					$p   = get_post( $share['id'] );
					$url = get_bloginfo( 'url' ) . '/?p=' . $p->ID . '&shareadraft=' . $share['key'];
					?>
					<tr>
						<td><?php echo esc_html( $p->ID ); ?></td>
						<td><?php echo esc_html( $p->post_title ); ?></td>
						<!-- TODO: make the draft link selecatble -->
						<td><a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $url ); ?></a></td>
						<td><?php echo esc_html( $this->friendly_delta( $share['expires'] - time() ) ); ?></td>
						<td class="actions">
							<a class="shareadraft-extend edit" id="shareadraft-extend-link-<?php echo esc_attr( $share['key'] ); ?>"
							   href="javascript:shareadraft.toggle_extend('<?php echo esc_js( $share['key'] ); ?>');">
								<?php esc_html_e( 'Extend', 'shareadraft' ); ?>
							</a>

							<form class="shareadraft-extend" id="shareadraft-extend-form-<?php echo esc_attr( $share['key'] ); ?>"
							      action="" method="post">
								<input type="hidden" name="action" value="extend" />
								<input type="hidden" name="key" value="<?php echo esc_attr( $share['key'] ); ?>" />
								<input type="submit" class="button" name="shareadraft_extend_submit"
								       value="<?php echo esc_attr__( 'Extend', 'shareadraft' ); ?>" />
								<?php esc_html_e( 'by', 'shareadraft' ); ?>
								<?php echo $this->tmpl_measure_select(); ?>
								<a class="shareadraft-extend-cancel"
								   href="javascript:shareadraft.cancel_extend('<?php echo esc_js( $share['key'] ); ?>');">
									<?php esc_html_e( 'Cancel', 'shareadraft' ); ?>
								</a>
							</form>
						</td>
						<td class="actions">
							<a class="delete" href="edit.php?page=<?php echo plugin_basename( __FILE__ ); ?>&amp;action=delete&amp;key=<?php echo esc_attr( $share['key'] ); ?>"><?php esc_html_e( 'Delete', 'shareadraft' ); ?></a>
						</td>
					</tr>
				<?php
				endforeach;
				if ( empty( $s ) ):
					?>
					<tr>
						<td colspan="5"><?php esc_html_e( 'No shared drafts!', 'shareadraft' ); ?></td>
					</tr>
				<?php
				endif;
				?>
				</tbody>
			</table>
			<h3><?php esc_html_e( 'Share a Draft', 'shareadraft' ); ?></h3>

			<form id="shareadraft-share" action="" method="post">
				<p>
					<select id="shareadraft-postid" name="post_id">
						<option value=""><?php esc_html_e( 'Choose a draft', 'shareadraft' ); ?></option>
						<?php
						foreach ( $drafts_struct as $draft_type ):
							if ( $draft_type[1] ):
								?>
								<option value="" disabled="disabled"></option>
								<option value="" disabled="disabled"><?php echo esc_html( $draft_type[0] ); ?></option>
								<?php
								foreach ( $draft_type[2] as $draft ):
									if ( empty( $draft->post_title ) ) {
										continue;
									}
									?>
									<option value="<?php echo esc_attr( $draft->ID ); ?>"><?php echo esc_html( $draft->post_title ); ?></option>
								<?php
								endforeach;
							endif;
						endforeach;
						?>
					</select>
				</p>
				<p>
					<input type="submit" class="button" name="shareadraft_submit"
					       value="<?php echo esc_attr( __( 'Share it', 'shareadraft' ) ); ?>" />
					<?php esc_html_e( 'for', 'shareadraft' ); ?>
					<?php echo $this->tmpl_measure_select(); ?>.
				</p>
			</form>
		</div>
	<?php
	}

	/**
	 * Dtermind whether or not a post can be viewd.
	 *
	 * @param int $post_id
	 *
	 * @return bool
	 */
	function can_view( $post_id ) {
		if ( ! isset( $_GET['shareadraft'] ) || ! is_array( $this->admin_options ) ) {
			return false;
		}
		foreach ( $this->admin_options as $option ) {
			if ( ! is_array( $option ) || ! isset( $option['shared'] ) ) {
				continue;
			}
			$shares = $option['shared'];
			foreach ( $shares as $share ) {
				if ( $share['id'] === $post_id && $share['key'] === $_GET['shareadraft'] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Intercept posts results.
	 *
	 * @param array $posts
	 *
     * @return mixed
	 */
	function posts_results_intercept( $posts ) {
		if ( 1 !== count( $posts ) ) {
			return $posts;
		}
		$post   = $posts[0];
		$status = get_post_status( $post );
		if ( 'publish' !== $status && $this->can_view( $post->ID ) ) {
			$this->shared_post = $post;
		}

		return $posts;
	}

	/**
	 * Intercept the posts
	 *
	 * @param array $posts
	 *
	 * @return array
	 */
	function the_posts_intercept( $posts ) {
		if ( empty( $posts ) && ! is_null( $this->shared_post ) ) {
			return array( $this->shared_post );
		} else {
			$this->shared_post = null;

			return $posts;
		}
	}

	/**
	 * Get the template for the expiration date
	 *
	 * @return string
	 */
	function tmpl_measure_select() {
		$secs  = esc_html__( 'seconds', 'shareadraft' );
		$mins  = esc_html__( 'minutes', 'shareadraft' );
		$hours = esc_html__( 'hours', 'shareadraft' );
		$days  = esc_html__( 'days', 'shareadraft' );

		return <<<SELECT
			<input name="expires" type="text" value="2" size="4"/>
			<select name="measure">
				<option value="s">$secs</option>
				<option value="m">$mins</option>
				<option value="h" selected="selected">$hours</option>
				<option value="d">$days</option>
			</select>
SELECT;
	}

	/**
	 * Print admin CSS
	 */
	function print_admin_css() {
		?>
		<style type="text/css">
			a.shareadraft-extend, a.shareadraft-extend-cancel {
				display: none;
			}

			form.shareadraft-extend {
				white-space: nowrap;
			}

			form.shareadraft-extend, form.shareadraft-extend input, form.shareadraft-extend select {
				font-size: 11px;
			}

			th.actions, td.actions {
				text-align: center;
			}
		</style>
	<?php
	}

	/**
	 * Print admin JavaScript
	 */
	function print_admin_js() {
		?>
		<script type="text/javascript">
			//<![CDATA[
			(function ( $ ) {
				$( function () {
					$( 'form.shareadraft-extend' ).hide();
					$( 'a.shareadraft-extend' ).show();
					$( 'a.shareadraft-extend-cancel' ).show().css( 'display', 'inline' );
				} );
				window.shareadraft = {
					toggle_extend: function ( key ) {
						var form = $( document.getElementById( 'shareadraft-extend-form-' + key ) );

						$( document.getElementById( 'shareadraft-extend-link-' + key ) ).hide();
						form.show();
						form.find( 'input[name="expires"]' ).focus();
					},
					cancel_extend: function ( key ) {
						$( document.getElementById( 'shareadraft-extend-form-' + key ) ).hide();
						$( document.getElementById( 'shareadraft-extend-link-' + key ) ).show();
					}
				};
			})( jQuery );
			//]]>
		</script>
	<?php
	}
}
endif;

if ( class_exists( 'ShareADraft' ) ) {
	$__share_a_draft = new ShareADraft();
}
