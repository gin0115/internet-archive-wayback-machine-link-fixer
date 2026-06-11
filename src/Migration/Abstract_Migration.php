<?php

/**
 * Abstract Migration.
 *
 * @since 0.1.0
 */

declare(strict_types=1);

namespace Internet_Archive\Wayback_Machine_Link_Fixer\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract Migration.
 */
abstract class Abstract_Migration {

	/**
	 * Runs on create/activation
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $table_name Optional table name.
	 *
	 * @return void
	 */
	abstract public function up( ?string $table_name = null ): void;

	/**
	 * Runs when on drop/deactivation
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $table_name Optional table name.
	 *
	 * @return void
	 */
	abstract public function down( ?string $table_name = null ): void;
}
