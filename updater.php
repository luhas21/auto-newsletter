<?php
/**
 * Custom Plugin Updater – stahování aktualizací z vlastního update serveru.
 * Aktivní pouze pokud je definovano AUTO_NEWSLETTER_DEV_UPDATER v wp-config.php.
 *
 * @package AutoNewsletter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Auto_Newsletter_Updater {
	private string $plugin_slug;
	private string $plugin_file;
	private string $plugin_basename;
	private string $update_url;

	public function __construct( string $plugin_file, string $update_url ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->plugin_slug     = dirname( $this->plugin_basename );
		$this->update_url      = $update_url;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_api_call' ), 10, 3 );
	}

	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}

		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = wp_remote_get( $this->update_url, array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/json' ),
		) );

		if ( is_wp_error( $remote ) || wp_remote_retrieve_response_code( $remote ) !== 200 ) {
			return $transient;
		}

		$remote = json_decode( wp_remote_retrieve_body( $remote ) );

		if ( ! $remote || empty( $remote->version ) || empty( $remote->download_url ) ) {
			return $transient;
		}

		$current_version = $transient->checked[ $this->plugin_basename ] ?? AUTO_NEWSLETTER_VERSION;

		if ( version_compare( $current_version, $remote->version, '<' ) ) {
			$transient->response[ $this->plugin_basename ] = (object) array(
				'id'            => 'w.org/plugins/' . $this->plugin_slug,
				'slug'          => $this->plugin_slug,
				'plugin'        => $this->plugin_basename,
				'new_version'   => $remote->version,
				'url'           => $remote->details_url ?? '',
				'package'       => $remote->download_url,
				'requires'      => $remote->requires ?? '',
				'tested'        => $remote->tested ?? '',
				'requires_php'  => $remote->requires_php ?? '',
			);
		} else {
			unset( $transient->response[ $this->plugin_basename ] );
		}

		return $transient;
	}

	public function plugin_api_call( $result, $action, $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}

		if ( empty( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $result;
		}

		$remote = wp_remote_get( $this->update_url, array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/json' ),
		) );

		if ( is_wp_error( $remote ) || wp_remote_retrieve_response_code( $remote ) !== 200 ) {
			return $result;
		}

		$remote = json_decode( wp_remote_retrieve_body( $remote ) );

		if ( ! $remote || empty( $remote->version ) ) {
			return $result;
		}

		return (object) array(
			'name'           => $remote->name ?? $this->plugin_slug,
			'slug'           => $this->plugin_slug,
			'version'        => $remote->version,
			'author'         => $remote->author ?? '',
			'author_profile' => $remote->author_profile ?? '',
			'requires'       => $remote->requires ?? '',
			'tested'         => $remote->tested ?? '',
			'requires_php'   => $remote->requires_php ?? '',
			'download_link'  => $remote->download_url ?? '',
			'last_updated'   => $remote->last_updated ?? '',
			'sections'       => array(
				'description' => $remote->description ?? '',
				'changelog'   => $remote->changelog ?? '',
			),
		);
	}
}