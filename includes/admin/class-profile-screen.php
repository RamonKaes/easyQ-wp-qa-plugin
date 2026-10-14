<?php
/**
 * Q&A section of the user profile screen.
 *
 * @package easyQ
 */

namespace EasyQ\Admin;

use EasyQ\Actor;
use EasyQ\Capabilities;
use EasyQ\Services;
use EasyQ\Users\Levels;
use EasyQ\Users\User_Status;
use WP_User;
use EasyQ\Users\Site_Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the Q&A profile fields, level, status and points to the WordPress profile screen.
 *
 * Everyone who may edit a user can change the profile fields. Moderators can
 * confirm, approve and block users; administrators of the plugin can set levels
 * and bonus points, but never above their own level.
 */
final class Profile_Screen {

	/**
	 * Nonce action prefix; the user ID is appended.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'easyq_profile_';

	/**
	 * Name of the nonce field.
	 *
	 * @var string
	 */
	private const NONCE_FIELD = 'easyq_profile_nonce';

	/**
	 * Hooks the section into the profile screens.
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'handle_save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'handle_save' ) );
	}

	/**
	 * Outputs the Q&A section.
	 *
	 * @param WP_User $user The user being edited.
	 */
	public function render( WP_User $user ): void {
		$editor = Actor::current();
		$fields = Services::user_fields()->all();

		wp_nonce_field( self::NONCE_ACTION . $user->ID, self::NONCE_FIELD );
		?>
		<h2><?php esc_html_e( 'Questions and answers', 'easyq' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php foreach ( $fields as $field ) : ?>
				<?php $field_id = 'easyq-field-' . $field->id; ?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $field->display_label() ); ?></label></th>
					<td>
						<?php if ( $field->is_multi_line() ) : ?>
							<textarea id="<?php echo esc_attr( $field_id ); ?>" name="easyq_fields[<?php echo esc_attr( (string) $field->id ); ?>]" rows="5" cols="30"><?php echo esc_textarea( Services::user_fields()->value( $user->ID, $field ) ); ?></textarea>
						<?php else : ?>
							<input type="<?php echo $field->is_link() ? 'url' : 'text'; ?>" class="regular-text" id="<?php echo esc_attr( $field_id ); ?>" name="easyq_fields[<?php echo esc_attr( (string) $field->id ); ?>]" value="<?php echo esc_attr( Services::user_fields()->value( $user->ID, $field ) ); ?>">
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>

			<tr>
				<th scope="row"><?php esc_html_e( 'Points', 'easyq' ); ?></th>
				<td>
					<?php echo esc_html( number_format_i18n( Services::points()->get( $user->ID ) ) ); ?>
					<?php if ( current_user_can( Capabilities::MANAGE ) ) : ?>
						<p>
							<label for="easyq-bonus"><?php esc_html_e( 'Bonus points', 'easyq' ); ?></label>
							<input type="number" class="small-text" id="easyq-bonus" name="easyq_bonus" value="<?php echo esc_attr( (string) $this->bonus( $user->ID ) ); ?>" step="1">
						</p>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Level', 'easyq' ); ?></th>
				<td>
					<?php if ( $this->can_set_levels( $editor, $user->ID ) ) : ?>
						<label for="easyq-level" class="screen-reader-text"><?php esc_html_e( 'Level', 'easyq' ); ?></label>
						<select id="easyq-level" name="easyq_level">
							<option value=""><?php esc_html_e( '— Based on the user’s role —', 'easyq' ); ?></option>
							<?php foreach ( $this->assignable_levels( $editor ) as $level => $label ) : ?>
								<option value="<?php echo esc_attr( (string) $level ); ?>" <?php selected( Services::user_levels()->assigned_level( $user->ID ), $level ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php
							/* translators: %s: name of the level. */
							echo esc_html( sprintf( __( 'Current level: %s', 'easyq' ), $this->level_label( Services::user_levels()->level( $user->ID ) ) ) );
							?>
						</p>
					<?php else : ?>
						<?php echo esc_html( $this->level_label( Services::user_levels()->level( $user->ID ) ) ); ?>
					<?php endif; ?>
				</td>
			</tr>

			<?php if ( $this->can_set_levels( $editor, $user->ID ) ) : ?>
				<?php $this->render_category_levels( $editor, $user ); ?>
			<?php endif; ?>

			<?php if ( current_user_can( Capabilities::MODERATE ) && $editor->user_id !== $user->ID ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'easyq' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Status', 'easyq' ); ?></legend>
							<label><input type="checkbox" name="easyq_confirmed" value="1" <?php checked( User_Status::is_confirmed( $user->ID ) ); ?>> <?php esc_html_e( 'Email address confirmed', 'easyq' ); ?></label><br>
							<label><input type="checkbox" name="easyq_approved" value="1" <?php checked( ! User_Status::awaits_approval( $user->ID ) ); ?>> <?php esc_html_e( 'Approved', 'easyq' ); ?></label><br>
							<label><input type="checkbox" name="easyq_blocked" value="1" <?php checked( User_Status::is_blocked( $user->ID ) ); ?>> <?php esc_html_e( 'Blocked from posting, voting and flagging', 'easyq' ); ?></label>
						</fieldset>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Saves the Q&A section after verifying the nonce.
	 *
	 * @param int $user_id ID of the edited user.
	 */
	public function handle_save( int $user_id ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) && is_string( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION . $user_id ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$input = array();
		foreach ( array( 'easyq_fields', 'easyq_bonus', 'easyq_level', 'easyq_category_levels', 'easyq_confirmed', 'easyq_approved', 'easyq_blocked' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$input[ $key ] = map_deep( wp_unslash( $_POST[ $key ] ), 'sanitize_textarea_field' );
			}
		}

		$this->save( $user_id, $input, Actor::current() );
	}

	/**
	 * Saves the Q&A section from sanitized input, checking every change against the editor's capabilities.
	 *
	 * @param int                  $user_id ID of the edited user.
	 * @param array<string, mixed> $input   Submitted values.
	 * @param Actor                $editor  Who saves the profile.
	 */
	public function save( int $user_id, array $input, Actor $editor ): void {
		$editor_id = (int) $editor->user_id;

		if ( ! user_can( $editor_id, 'edit_user', $user_id ) ) {
			return;
		}

		$values = is_array( $input['easyq_fields'] ?? null ) ? $input['easyq_fields'] : array();
		foreach ( Services::user_fields()->all() as $field ) {
			if ( isset( $values[ $field->id ] ) && is_string( $values[ $field->id ] ) ) {
				Services::user_fields()->set_value( $user_id, $field, $values[ $field->id ] );
			}
		}

		if ( user_can( $editor_id, Capabilities::MANAGE ) && isset( $input['easyq_bonus'] ) && is_numeric( $input['easyq_bonus'] ) ) {
			$bonus = (int) $input['easyq_bonus'];
			if ( $bonus !== $this->bonus( $user_id ) ) {
				Services::points()->set_bonus( $user_id, $bonus );
			}
		}

		if ( $this->can_set_levels( $editor, $user_id ) ) {
			$this->save_levels( $user_id, $input, $editor );
		}

		if ( user_can( $editor_id, Capabilities::MODERATE ) && $editor_id !== $user_id ) {
			$this->save_status( $user_id, $input, $editor );
		}
	}

	/**
	 * Saves the level and category levels.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $input   Submitted values.
	 * @param Actor                $editor  Who saves the profile.
	 */
	private function save_levels( int $user_id, array $input, Actor $editor ): void {
		$assignable = $this->assignable_levels( $editor );
		$levels     = Services::user_levels();

		if ( array_key_exists( 'easyq_level', $input ) ) {
			$level = is_numeric( $input['easyq_level'] ) ? (int) $input['easyq_level'] : null;
			if ( null === $level || isset( $assignable[ $level ] ) ) {
				$levels->set_level( $editor, $user_id, $level );
			}
		}

		$category_levels = is_array( $input['easyq_category_levels'] ?? null ) ? $input['easyq_category_levels'] : array();
		foreach ( $category_levels as $category_id => $level ) {
			if ( ! is_numeric( $category_id ) || null === Services::categories()->find( (int) $category_id ) ) {
				continue;
			}

			$level = is_numeric( $level ) ? (int) $level : null;
			if ( null === $level || isset( $assignable[ $level ] ) ) {
				$current = $levels->category_levels( $user_id )[ (int) $category_id ] ?? null;
				if ( $current !== $level ) {
					$levels->set_category_level( $editor, $user_id, (int) $category_id, $level );
				}
			}
		}
	}

	/**
	 * Saves the confirmation, approval and block status.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $input   Submitted values.
	 * @param Actor                $editor  Who saves the profile.
	 */
	private function save_status( int $user_id, array $input, Actor $editor ): void {
		if ( ! empty( $input['easyq_confirmed'] ) ) {
			User_Status::confirm( $editor, $user_id );
		} elseif ( User_Status::is_confirmed( $user_id ) ) {
			User_Status::unconfirm( $user_id );
		}

		if ( ! empty( $input['easyq_approved'] ) ) {
			if ( User_Status::awaits_approval( $user_id ) ) {
				User_Status::approve( $editor, $user_id );
			}
		} else {
			Site_Meta::update( $user_id, User_Status::UNAPPROVED_META, 1 );
			Site_Meta::delete( $user_id, User_Status::APPROVED_META );
		}

		if ( ! empty( $input['easyq_blocked'] ) ) {
			User_Status::block( $editor, $user_id );
		} else {
			User_Status::unblock( $editor, $user_id );
		}
	}

	/**
	 * Outputs the category level rows.
	 *
	 * @param Actor   $editor Who edits the profile.
	 * @param WP_User $user   The user being edited.
	 */
	private function render_category_levels( Actor $editor, WP_User $user ): void {
		$categories = Services::categories()->all();
		if ( array() === $categories ) {
			return;
		}

		$current = Services::user_levels()->category_levels( $user->ID );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Levels in categories', 'easyq' ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'Levels in categories', 'easyq' ); ?></legend>
					<?php foreach ( $categories as $category ) : ?>
						<?php $select_id = 'easyq-category-level-' . $category->id; ?>
						<p>
							<label for="<?php echo esc_attr( $select_id ); ?>"><?php echo esc_html( implode( ' › ', array_map( static fn ( $ancestor ): string => $ancestor->title, Services::categories()->path( $category->id ) ) ) ); ?></label><br>
							<select id="<?php echo esc_attr( $select_id ); ?>" name="easyq_category_levels[<?php echo esc_attr( (string) $category->id ); ?>]">
								<option value=""><?php esc_html_e( '— No special level —', 'easyq' ); ?></option>
								<?php foreach ( $this->assignable_levels( $editor ) as $level => $label ) : ?>
									<?php if ( $level >= Levels::EXPERT ) : ?>
										<option value="<?php echo esc_attr( (string) $level ); ?>" <?php selected( $current[ $category->id ] ?? null, $level ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select>
						</p>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'A level in a category also applies to its subcategories.', 'easyq' ); ?></p>
				</fieldset>
			</td>
		</tr>
		<?php
	}

	/**
	 * Checks whether the editor may change the levels of a user.
	 *
	 * Nobody can change their own level, and the edited user's level must not be above the editor's.
	 *
	 * @param Actor $editor  Who edits the profile.
	 * @param int   $user_id The user being edited.
	 * @return bool
	 */
	private function can_set_levels( Actor $editor, int $user_id ): bool {
		if ( null === $editor->user_id || $editor->user_id === $user_id || ! user_can( $editor->user_id, Capabilities::MANAGE ) ) {
			return false;
		}

		return ( Services::user_levels()->level( $user_id ) ?? 0 ) <= $this->editor_level( $editor );
	}

	/**
	 * Returns the levels an editor may assign: up to their own level.
	 *
	 * @param Actor $editor Who edits the profile.
	 * @return array<int, string>
	 */
	private function assignable_levels( Actor $editor ): array {
		$maximum = $this->editor_level( $editor );

		return array_filter( Levels::labels(), static fn ( int $level ): bool => $level <= $maximum, ARRAY_FILTER_USE_KEY );
	}

	/**
	 * Returns the level of the editor; WordPress administrators count as Q&A administrators.
	 *
	 * @param Actor $editor Who edits the profile.
	 * @return int
	 */
	private function editor_level( Actor $editor ): int {
		$level = Services::user_levels()->level( $editor->user_id ) ?? 0;

		return null !== $editor->user_id && user_can( $editor->user_id, 'manage_options' ) ? max( $level, Levels::ADMIN ) : $level;
	}

	/**
	 * Returns the bonus points of a user.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	private function bonus( int $user_id ): int {
		$wpdb = \EasyQ\Database\Db::wpdb();

		return \EasyQ\Database\Db::get_int(
			$wpdb->prepare( 'SELECT bonus FROM %i WHERE user_id = %d', \EasyQ\Database\Schema::table( 'userpoints' ), $user_id )
		);
	}

	/**
	 * Returns the name of a level.
	 *
	 * @param int|null $level Level.
	 * @return string
	 */
	private function level_label( ?int $level ): string {
		return Levels::labels()[ $level ?? Levels::BASIC ] ?? (string) $level;
	}
}
