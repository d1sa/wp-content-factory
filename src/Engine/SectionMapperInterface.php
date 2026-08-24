<?php

namespace ContentFactory\Engine;

use ContentFactory\Contract\BlockNode;

defined( 'ABSPATH' ) || exit;

interface SectionMapperInterface {
	public function map( array $section, array $context = array() ): BlockNode;
}
