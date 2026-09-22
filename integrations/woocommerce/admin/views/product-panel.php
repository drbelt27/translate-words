<?php
/**
 * The translation entry panel markup.
 *
 * @package Linguator
 *
 * @var string $language        The language being edited.
 * @var array  $descriptors     The translatable fields.
 * @var array  $record          The stored record for this language.
 * @var object $repository      Translation storage.
 * @var object $post            The product post.
 * @var array  $language_switch Language slug mapped to array( 'url', 'current' ).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Don't access directly.
}

use Linguator\Integrations\woocommerce\Admin\Product_Panel;

wp_nonce_field( Product_Panel::NONCE_ACTION, Product_Panel::NONCE_FIELD );
?>
<input type="hidden" name="lmat_wc_language" value="<?php echo esc_attr( $language ); ?>" />
<input type="hidden" name="lmat_wc_revision" value="<?php echo esc_attr( (string) $record['revision'] ); ?>" />

<p>
	<strong><?php echo esc_html__( 'Language', 'translate-words' ); ?>:</strong>
	<?php foreach ( (array) $language_switch as $slug => $link ) : ?>
		<?php if ( ! empty( $link['current'] ) ) : ?>
			<span aria-current="page"><?php echo esc_html( $slug ); ?></span>
		<?php else : ?>
			<a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $slug ); ?></a>
		<?php endif; ?>
	<?php endforeach; ?>
</p>

<table class="widefat striped">
	<tbody>
	<?php foreach ( $descriptors as $descriptor ) : ?>
		<?php
		$status = $repository->get_status( (int) $post->ID, $language, $descriptor['key'], $descriptor['source'] );
		$value  = isset( $record['fields'][ $descriptor['key'] ]['value'] ) ? (string) $record['fields'][ $descriptor['key'] ]['value'] : '';
		$id     = 'lmat-wc-' . sanitize_key( $descriptor['key'] );
		?>
		<tr>
			<th scope="row">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $descriptor['label'] ); ?></label>
				<p class="description"><?php echo esc_html( $status ); ?></p>
			</th>
			<td>
				<p class="description"><?php echo esc_html( $descriptor['source'] ); ?></p>
				<?php if ( Product_Panel::TYPE_HTML === $descriptor['type'] ) : ?>
					<textarea id="<?php echo esc_attr( $id ); ?>" name="lmat_wc_fields[<?php echo esc_attr( $descriptor['key'] ); ?>]" rows="4" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
				<?php else : ?>
					<input type="text" id="<?php echo esc_attr( $id ); ?>" name="lmat_wc_fields[<?php echo esc_attr( $descriptor['key'] ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="large-text" />
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
