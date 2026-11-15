<?php
/**
 * The per-language permalink bases page.
 *
 * @package Linguator
 *
 * @var string[] $labels   Base type mapped to its label.
 * @var array    $bases    Language slug mapped to base type mapped to the stored base.
 * @var string[] $errors   The errors of the last save.
 * @var string[] $warnings The warnings of the last save.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

use Linguator\Integrations\woocommerce\Admin\Permalink_Bases;
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Translated permalink bases', 'translate-words' ); ?></h1>

	<?php foreach ( $errors as $message ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $message ); ?></p></div>
	<?php endforeach; ?>
	<?php foreach ( $warnings as $message ) : ?>
		<div class="notice notice-warning"><p><?php echo esc_html( $message ); ?></p></div>
	<?php endforeach; ?>

	<form method="post">
		<?php wp_nonce_field( Permalink_Bases::NONCE_ACTION, Permalink_Bases::NONCE_FIELD ); ?>
		<?php foreach ( $bases as $base_language => $by_type ) : ?>
			<h2><?php echo esc_html( $base_language ); ?></h2>
			<table class="form-table" role="presentation">
				<?php foreach ( $labels as $base_type => $label ) : ?>
					<tr>
						<th scope="row">
							<label for="lmat-wc-base-<?php echo esc_attr( $base_language . '-' . $base_type ); ?>"><?php echo esc_html( $label ); ?></label>
						</th>
						<td>
							<input type="text" id="lmat-wc-base-<?php echo esc_attr( $base_language . '-' . $base_type ); ?>" name="<?php echo esc_attr( Permalink_Bases::FIELD . '[' . $base_language . '][' . $base_type . ']' ); ?>" value="<?php echo esc_attr( $by_type[ $base_type ] ); ?>" class="regular-text" />
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endforeach; ?>
		<?php submit_button(); ?>
	</form>
</div>
