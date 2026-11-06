#!/usr/bin/env bash
# Real-concurrency check for Auth::access_token(): five PHP processes find the same
# expiring session at once, and exactly one of them may call /auth/session/refresh.
#
# Runs in the wp-env tests-cli environment. A temporary mu-plugin fakes the refresh
# endpoint (sleep 1 s, count the call atomically, answer new tokens). The mu-plugin, the
# session, the counter and the lock row are removed on exit.
#
# Prints refreshes=<n> and exits 0 only when n is 1 and every process got the new token.
# Exits 2 when the plugin is not active in tests-cli (see .wp-env.json afterStart).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

WP_ENV="$ROOT/node_modules/.bin/wp-env"
if [ ! -x "$WP_ENV" ]; then
	WP_ENV="npx wp-env"
fi
MU_FILE="ecomweb-refresh-race.php"
PROCESSES=5

# Runs a command in tests-cli and prints only its own output.
in_tests_cli() {
	$WP_ENV run tests-cli "$@" 2>/dev/null
}

if ! in_tests_cli wp plugin is-active ecomweb-for-woocommerce >/dev/null; then
	echo "ecomweb-for-woocommerce is not active in tests-cli." >&2
	echo "Run: $WP_ENV run tests-cli wp plugin activate woocommerce ecomweb-for-woocommerce" >&2
	exit 2
fi

MU_PLUGIN="$(cat <<'PHP'
<?php
/**
 * Temporary: written and removed by bin/refresh-race.sh. Fakes POST /auth/session/refresh.
 *
 * @package ecomweb-for-woocommerce
 */

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( '/auth/session/refresh' !== substr( (string) wp_parse_url( $url, PHP_URL_PATH ), -strlen( '/auth/session/refresh' ) ) ) {
			return $pre;
		}
		global $wpdb;
		sleep( 1 );
		$wpdb->query( "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = 'ecomweb_race_refreshes'" );
		$n = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'ecomweb_race_refreshes'" );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'access_token'  => 'RACE-AT-' . $n,
					'refresh_token' => 'RACE-RT-' . $n,
					'expires_at'    => time() + 900,
					'expires_in'    => 900,
					'user'          => array( 'id' => 'race', 'email' => 'race@example.test', 'name' => null ),
				)
			),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

function ecomweb_race_seed() {
	global $wpdb;
	delete_option( '_ecomweb_lock_session' );
	delete_option( 'ecomweb_race_refreshes' );
	add_option( 'ecomweb_race_refreshes', '0', '', false );
	( new Ecomweb\Woo\Session_Store() )->write(
		array(
			'access_token'       => 'RACE-AT-0',
			'refresh_token'      => 'RACE-RT-0',
			'expires_at'         => time() + 10,
			'email'              => 'race@example.test',
			'user_id'            => 'race',
			'api_url'            => Ecomweb\Woo\Config::api_url(),
			'signed_in_home_url' => Ecomweb\Woo\Auth::normalise_home( home_url() ),
		)
	);
}

function ecomweb_race_run() {
	$waited = 'no';
	add_action(
		'ecomweb_lock_wait',
		function () use ( &$waited ) {
			$waited = 'yes';
		}
	);
	$token = Ecomweb\Woo\Plugin::instance()->auth()->access_token();
	echo is_wp_error( $token ) ? 'error=' . $token->get_error_code() : 'token=' . $token, ' waited=', $waited, "\n";
}

function ecomweb_race_count() {
	global $wpdb;
	echo (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'ecomweb_race_refreshes'" ), "\n";
}

function ecomweb_race_cleanup() {
	( new Ecomweb\Woo\Session_Store() )->delete();
	delete_option( 'ecomweb_race_refreshes' );
	delete_option( '_ecomweb_lock_session' );
	delete_option( 'ecomweb_signed_out_reason' );
}
PHP
)"
MU_B64="$(printf '%s\n' "$MU_PLUGIN" | base64 | tr -d '\n')"

cleanup() {
	in_tests_cli wp eval "if ( function_exists( 'ecomweb_race_cleanup' ) ) { ecomweb_race_cleanup(); } wp_delete_file( WPMU_PLUGIN_DIR . '/$MU_FILE' );" >/dev/null || true
}
trap cleanup EXIT

in_tests_cli wp eval "wp_mkdir_p( WPMU_PLUGIN_DIR ); file_put_contents( WPMU_PLUGIN_DIR . '/$MU_FILE', base64_decode( '$MU_B64' ) );" >/dev/null
in_tests_cli wp eval 'ecomweb_race_seed();' >/dev/null

# All processes start inside one container exec, so they really overlap.
RESULTS="$(in_tests_cli sh -c "for i in \$(seq $PROCESSES); do wp eval 'ecomweb_race_run();' & done; wait" | grep -E '^(token|error)=' || true)"
REFRESHES="$(in_tests_cli wp eval 'ecomweb_race_count();' | tr -dc '0-9')"

echo "$RESULTS"
echo "refreshes=$REFRESHES"

OK_COUNT="$(printf '%s\n' "$RESULTS" | grep -c '^token=RACE-AT-1 ' || true)"
if [ "$REFRESHES" = "1" ] && [ "$OK_COUNT" = "$PROCESSES" ]; then
	exit 0
fi
echo "FAIL: expected refreshes=1 and $PROCESSES processes with token RACE-AT-1 (got $OK_COUNT)." >&2
exit 1
