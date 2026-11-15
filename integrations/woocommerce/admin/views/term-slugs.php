<?php
/**
 * The translated slug fields of the term edit screen.
 *
 * @package Linguator
 *
 * @var string[] $slugs Language slug mapped to the stored translated slug, empty when none.
 * @var string   $error The error of the last save, empty when none.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

use Linguator\Integrations\woocommerce\Admin\Term_Slugs;
?>
<tr class="form-field">
	<th scope="row" colspan="2">
		<?php wp_nonce_field( Term_Slugs::NONCE_ACTION, Term_Slugs::NONCE_FIELD, false ); ?>
		<strong><?php echo esc_html__( 'Translated slugs', 'translate-words' ); ?></strong>
		<?php if ( '' !== $error ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>
	</th>
</tr>
<?php foreach ( $slugs as $slug_language => $slug_value ) : ?>
	<tr class="form-field">
		<th scope="row">
			<label for="lmat-wc-term-slug-<?php echo esc_attr( $slug_language ); ?>"><?php echo esc_html( $slug_language ); ?></label>
		</th>
		<td>
			<input type="text" id="lmat-wc-term-slug-<?php echo esc_attr( $slug_language ); ?>" name="<?php echo esc_attr( Term_Slugs::FIELD . '[' . $slug_language . ']' ); ?>" value="<?php echo esc_attr( $slug_value ); ?>" />
			<p class="description"><?php echo esc_html__( 'The last part of this term\'s URL in this language. Left empty, it is created from the translated name when one exists.', 'translate-words' ); ?></p>
		</td>
	</tr>
<?php endforeach; ?>
