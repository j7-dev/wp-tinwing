<?php
/**
 * Base
 */

declare (strict_types = 1);

namespace J7\WpTinwing\Utils;

/**
 * Class Base
 */
abstract class Base {
	const BASE_URL      = '/';
	const APP1_SELECTOR = '#wp_tinwing';
	const APP2_SELECTOR = '#wp_tinwing_metabox';
	const API_TIMEOUT   = '30000';
	const DEFAULT_IMAGE = 'http://1.gravatar.com/avatar/1c39955b5fe5ae1bf51a77642f052848?s=96&d=mm&r=g';
}
