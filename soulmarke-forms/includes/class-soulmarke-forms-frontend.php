<?php
/** Public shortcode and presentation for Soulmarke Forms. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Soulmarke_Forms_Frontend {
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_shortcode( 'soulmarke_form', array( __CLASS__, 'render' ) );
	}

	public static function register_assets() {
		wp_register_style( 'soulmarke-forms', SOULMARKE_FORMS_URL . 'assets/frontend.css', array(), SOULMARKE_FORMS_VERSION );
		wp_register_script( 'soulmarke-forms', SOULMARKE_FORMS_URL . 'assets/frontend.js', array(), SOULMARKE_FORMS_VERSION, true );
		$post = get_post();
		if ( $post && has_shortcode( $post->post_content, 'soulmarke_form' ) ) {
			wp_enqueue_style( 'soulmarke-forms' );
			wp_enqueue_script( 'soulmarke-forms' );
		}
	}

	public static function render() {
		if ( ! wp_script_is( 'soulmarke-forms', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'soulmarke-forms' );
		wp_enqueue_script( 'soulmarke-forms' );
		$settings  = Soulmarke_Forms::settings();
		$questions = $settings['questions'];
		$instance  = wp_unique_id( 'smf-' );
		$total     = count( $questions );
		$config    = array(
			'endpoint' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'soulmarke_submit' ),
			'messages' => array(
				'required' => __( 'Please answer this question before continuing.', 'soulmarke-forms' ),
				'sending'  => __( 'Sending your response…', 'soulmarke-forms' ),
				'error'    => __( 'We could not send your response. Please try again.', 'soulmarke-forms' ),
				'success'  => $settings['success_message'],
				'question' => __( 'Question %1$s of %2$s', 'soulmarke-forms' ),
				'maximum'  => __( 'Choose up to %s options.', 'soulmarke-forms' ),
				'other'    => __( 'Please add a little more detail for your answer.', 'soulmarke-forms' ),
			),
		);
		ob_start();
		?>
		<section class="smf-form" id="<?php echo esc_attr( $instance ); ?>" aria-labelledby="<?php echo esc_attr( $instance . '-title' ); ?>">
			<div class="smf-banner">
				<div class="smf-brand"><span class="smf-brand-mark" aria-hidden="true">S</span><span>Soulmarke</span></div>
				<div class="smf-banner-copy">
					<p class="smf-eyebrow"><?php esc_html_e( 'A moment for your perspective', 'soulmarke-forms' ); ?></p>
					<h2 id="<?php echo esc_attr( $instance . '-title' ); ?>"><?php echo esc_html( $settings['title'] ); ?></h2>
					<?php if ( ! empty( $settings['description'] ) ) : ?>
						<p class="smf-description"><?php echo nl2br( esc_html( $settings['description'] ) ); ?></p>
					<?php endif; ?>
				</div>
				<div class="smf-banner-orbit" aria-hidden="true"></div>
			</div>
			<div class="smf-body">
				<?php if ( ! $total ) : ?>
					<div class="smf-empty"><p class="smf-eyebrow"><?php esc_html_e( 'Coming soon', 'soulmarke-forms' ); ?></p><h3><?php esc_html_e( 'We’re preparing your questions.', 'soulmarke-forms' ); ?></h3><p><?php esc_html_e( 'Please check back soon to share your perspective.', 'soulmarke-forms' ); ?></p></div>
				<?php else : ?>
					<noscript><p class="smf-noscript"><?php esc_html_e( 'Please enable JavaScript to complete and submit this form.', 'soulmarke-forms' ); ?></p></noscript>
					<div class="smf-progress-area">
						<div class="smf-progress-meta"><span class="smf-progress-label"><?php echo esc_html( sprintf( __( 'Question %1$s of %2$s', 'soulmarke-forms' ), '1', $total ) ); ?></span><span class="smf-progress-percent" aria-hidden="true"><?php echo esc_html( round( 100 / $total ) ); ?>%</span></div>
						<div class="smf-progress" role="progressbar" aria-label="<?php esc_attr_e( 'Form progress', 'soulmarke-forms' ); ?>" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( $total ); ?>" aria-valuenow="1"><span class="smf-progress-fill" style="width: <?php echo esc_attr( round( 100 / $total, 2 ) ); ?>%"></span></div>
					</div>
					<form class="smf-question-form" novalidate>
						<div class="smf-honeypot" aria-hidden="true"><label for="<?php echo esc_attr( $instance . '-website' ); ?>"><?php esc_html_e( 'Leave this field empty', 'soulmarke-forms' ); ?></label><input id="<?php echo esc_attr( $instance . '-website' ); ?>" name="website" type="text" tabindex="-1" autocomplete="off"></div>
						<?php foreach ( $questions as $index => $question ) : ?>
							<?php $question_dom_id = $instance . '-question-' . $index; ?>
							<fieldset class="smf-question" data-question-id="<?php echo esc_attr( $question['id'] ); ?>" data-type="<?php echo esc_attr( $question['type'] ); ?>" data-required="<?php echo $question['required'] ? 'true' : 'false'; ?>" data-max-selections="<?php echo esc_attr( isset( $question['max_selections'] ) ? $question['max_selections'] : 0 ); ?>" data-other-option="<?php echo esc_attr( isset( $question['other_option'] ) ? $question['other_option'] : '' ); ?>" aria-describedby="<?php echo esc_attr( $question_dom_id . '-description ' . $question_dom_id . '-error' ); ?>">
								<legend class="smf-question-title" tabindex="-1"><span class="smf-question-number" aria-hidden="true"><?php echo esc_html( str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span><span><?php echo esc_html( $question['title'] ); ?></span></legend>
								<div id="<?php echo esc_attr( $question_dom_id . '-description' ); ?>" class="smf-question-description"><?php if ( ! empty( $question['description'] ) ) { echo nl2br( esc_html( $question['description'] ) ); } ?><span class="smf-required-note"><?php echo $question['required'] ? esc_html__( 'Required', 'soulmarke-forms' ) : esc_html__( 'Optional', 'soulmarke-forms' ); ?></span></div>
								<?php self::render_input( $question, $question_dom_id ); ?>
								<?php if ( ! empty( $question['other_option'] ) ) : ?>
									<div class="smf-other" hidden><label for="<?php echo esc_attr( $question_dom_id . '-other' ); ?>"><?php esc_html_e( 'Please tell us more', 'soulmarke-forms' ); ?></label><input class="smf-input smf-other-input" id="<?php echo esc_attr( $question_dom_id . '-other' ); ?>" name="<?php echo esc_attr( 'other_answer[' . $question['id'] . ']' ); ?>" type="text" maxlength="1000" aria-describedby="<?php echo esc_attr( $question_dom_id . '-error' ); ?>" disabled></div>
								<?php endif; ?>
								<p class="smf-field-error" id="<?php echo esc_attr( $question_dom_id . '-error' ); ?>" hidden></p>
							</fieldset>
						<?php endforeach; ?>
						<div class="smf-navigation"><button type="button" class="smf-button smf-button-back" hidden><span aria-hidden="true">←</span> <?php esc_html_e( 'Back', 'soulmarke-forms' ); ?></button><button type="button" class="smf-button smf-button-next"><?php esc_html_e( 'Continue', 'soulmarke-forms' ); ?> <span aria-hidden="true">→</span></button><button type="submit" class="smf-button smf-button-submit" hidden><?php esc_html_e( 'Send my response', 'soulmarke-forms' ); ?> <span aria-hidden="true">↗</span></button></div>
						<p class="smf-keyboard-hint" aria-hidden="true"><?php esc_html_e( 'Take your time. You can go back to review your answers.', 'soulmarke-forms' ); ?></p>
					</form>
					<div class="smf-success" hidden><span class="smf-success-icon" aria-hidden="true">✓</span><p class="smf-eyebrow"><?php esc_html_e( 'Response received', 'soulmarke-forms' ); ?></p><h3 tabindex="-1"><?php esc_html_e( 'Thank you for sharing.', 'soulmarke-forms' ); ?></h3><p class="smf-success-message"></p></div>
					<p class="smf-status" role="status" aria-live="polite" aria-atomic="true"></p>
					<p class="smf-privacy"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none"><path d="M7 10V7a5 5 0 0 1 10 0v3M5 10h14v11H5z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg><?php esc_html_e( 'Your response is sent privately to the team.', 'soulmarke-forms' ); ?></p>
					<script type="application/json" class="smf-config"><?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
				<?php endif; ?>
			</div>
		</section>
		<?php
		return ob_get_clean();
	}

	private static function render_input( $question, $id ) {
		$type     = $question['type'];
		$required = $question['required'] ? ' required' : '';
		$name     = 'answer[' . $question['id'] . ']';
		$describe = $id . '-description ' . $id . '-error';
		$label    = $question['title'];
		if ( in_array( $type, array( 'radio', 'checkbox', 'rating' ), true ) ) {
			$options = 'rating' === $type ? array( '1', '2', '3', '4', '5' ) : $question['options'];
			$input_type = 'checkbox' === $type ? 'checkbox' : 'radio';
			?>
			<div class="smf-choices<?php echo 'rating' === $type ? ' smf-rating' : ''; ?>">
				<?php foreach ( $options as $option_index => $option ) : ?>
					<label class="smf-choice" for="<?php echo esc_attr( $id . '-option-' . $option_index ); ?>"><input class="smf-answer-control" id="<?php echo esc_attr( $id . '-option-' . $option_index ); ?>" name="<?php echo esc_attr( $name . ( 'checkbox' === $type ? '[]' : '' ) ); ?>" type="<?php echo esc_attr( $input_type ); ?>" value="<?php echo esc_attr( $option ); ?>" aria-describedby="<?php echo esc_attr( $describe ); ?>"<?php echo 'radio' === $input_type ? $required : ''; ?>><span class="smf-choice-indicator" aria-hidden="true"></span><span class="smf-choice-text"><?php echo esc_html( $option ); ?></span></label>
				<?php endforeach; ?>
			</div>
			<?php if ( 'rating' === $type ) : ?><p class="smf-rating-hint"><?php esc_html_e( '1 = lowest · 5 = highest', 'soulmarke-forms' ); ?></p><?php endif; ?>
			<?php if ( 'checkbox' === $type && ! empty( $question['max_selections'] ) ) : ?><p class="smf-selection-hint"><?php echo esc_html( sprintf( __( 'Choose up to %s options.', 'soulmarke-forms' ), $question['max_selections'] ) ); ?></p><?php endif; ?>
			<?php
		} elseif ( 'textarea' === $type ) {
			?><textarea class="smf-input smf-answer-control" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="5" maxlength="5000" aria-label="<?php echo esc_attr( $label ); ?>" aria-describedby="<?php echo esc_attr( $describe ); ?>" placeholder="<?php esc_attr_e( 'Share your thoughts…', 'soulmarke-forms' ); ?>"<?php echo $required; ?>></textarea><?php
		} elseif ( 'select' === $type ) {
			?><select class="smf-input smf-select smf-answer-control" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" aria-label="<?php echo esc_attr( $label ); ?>" aria-describedby="<?php echo esc_attr( $describe ); ?>"<?php echo $required; ?>><option value=""><?php esc_html_e( 'Choose an option', 'soulmarke-forms' ); ?></option><?php foreach ( $question['options'] as $option ) : ?><option value="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $option ); ?></option><?php endforeach; ?></select><?php
		} else {
			?><input class="smf-input smf-answer-control" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" type="<?php echo esc_attr( $type ); ?>"<?php echo 'number' === $type ? ' step="any"' : ' maxlength="5000"'; ?><?php echo 'email' === $type ? ' autocomplete="email" inputmode="email"' : ''; ?> aria-label="<?php echo esc_attr( $label ); ?>" aria-describedby="<?php echo esc_attr( $describe ); ?>" placeholder="<?php echo esc_attr( 'email' === $type ? __( 'you@example.com', 'soulmarke-forms' ) : __( 'Type your answer here…', 'soulmarke-forms' ) ); ?>"<?php echo $required; ?>><?php
		}
	}
}
