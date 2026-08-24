<?php

namespace ContentFactory\Engine;

defined( 'ABSPATH' ) || exit;

/** Emitted contracts for allowlisted named mappers; executable code remains in PHP. */
final class MapperDefinitionRegistry {
	public function attributes( string $id ): array {
		$definitions = array(
			'potolki.hero' => array( 'kicker'=>'string','title'=>'string','lead1'=>'string','lead2'=>'string','hasLeadBlocks'=>'boolean','buttonLabel'=>'string','buttonUrl'=>'string','buttonTarget'=>'string','buttonRel'=>'string','phoneCaption'=>'string','phoneLabel'=>'string','phoneUrl'=>'string','benefit1'=>'string','benefit2'=>'string','benefit3'=>'string','imageId'=>'number','imageUrl'=>'string','imageAlt'=>'string','badgeValue'=>'string','badgeText'=>'string','noteTitle'=>'string','noteText'=>'string' ),
			'potolki.article' => array( 'sectionId'=>'string','sectionIndex'=>'string','title'=>'string','accent'=>'boolean' ),
			'potolki.parent-link' => array( 'label'=>'string','linkLabel'=>'string','linkUrl'=>'string' ),
			'potolki.cta' => array( 'sectionId'=>'string','variant'=>array('type'=>'string','enum'=>array('form','links')),'kicker'=>'string','title'=>'string','text'=>'string','benefit1'=>'string','benefit2'=>'string','nameLabel'=>'string','namePlaceholder'=>'string','phoneLabel'=>'string','phonePlaceholder'=>'string','buttonLabel'=>'string','buttonUrl'=>'string','buttonTarget'=>'string','buttonRel'=>'string','secondaryLabel'=>'string','secondaryUrl'=>'string','phoneCtaLabel'=>'string','phoneCtaUrl'=>'string','formNote'=>'string','ymCounterId'=>'string','ymGoal'=>'string' ),
			'potolki.content' => array( 'readingTime'=>'string','categoryLabel'=>'string','tocLabel'=>'string' ),
		);
		if ( ! isset( $definitions[ $id ] ) ) {
			throw new \InvalidArgumentException( 'Неизвестный named mapper: ' . $id );
		}
		return $definitions[ $id ];
	}

	public function additional_contracts( string $id ): array {
		if ( ! in_array( $id, array( 'potolki.hero', 'potolki.article' ), true ) ) {
			return array();
		}
		return array(
			'core/paragraph' => array( 'attributes' => array() ),
			'core/heading'   => array( 'attributes' => array( 'level' => 'number' ) ),
			'core/list'      => array( 'attributes' => array( 'ordered' => 'boolean' ) ),
			'core/list-item' => array( 'attributes' => array() ),
			'core/buttons'   => array( 'attributes' => array() ),
			'core/button'    => array( 'attributes' => array() ),
		);
	}

	/** @return string[] Top-level semantic data fields read by a named mapper. */
	public function consumer_fields( string $id ): array {
		$definitions = array(
			'potolki.hero' => array( 'kicker', 'title', 'lead', 'primaryAction', 'benefits', 'image', 'badge', 'note' ),
			'potolki.article' => array( 'title', 'accent', 'body' ),
			'potolki.parent-link' => array( 'label', 'linkLabel' ),
			'potolki.cta' => array( 'variant', 'kicker', 'title', 'text', 'benefits', 'primaryAction', 'secondaryAction' ),
			'potolki.content' => array(),
		);
		if ( ! isset( $definitions[ $id ] ) ) {
			throw new \InvalidArgumentException( 'Неизвестный named mapper: ' . $id );
		}
		return $definitions[ $id ];
	}
}
