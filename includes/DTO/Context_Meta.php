<?php
/**
 * DTO immuable représentant le contexte dynamique WordPress capturé lors de l'appel.
 */

declare(strict_types=1);

namespace EGMail\DTO;

readonly class Context_Meta {

	/**
	 * Constructeur avec promotion de propriétés.
	 *
	 * @param ?string $content_type Type MIME du message.
	 * @param ?string $charset      Jeu de caractères.
	 * @param ?string $from_email   Adresse e-mail d'expédition.
	 * @param ?string $from_name    Nom d'expédition.
	 */
	public function __construct(
		public ?string $content_type = null,
		public ?string $charset = null,
		public ?string $from_email = null,
		public ?string $from_name = null,
	) {}

	/**
	 * Convertit le DTO en tableau associatif.
	 *
	 * @return array<string, ?string>
	 */
	public function to_array(): array {
		return [
			'content_type' => $this->content_type,
			'charset'      => $this->charset,
			'from_email'   => $this->from_email,
			'from_name'    => $this->from_name,
		];
	}

	/**
	 * Sérialise le contexte en chaîne JSON.
	 */
	public function to_json(): string {
		$encoded = wp_json_encode( $this->to_array() );
		return is_string( $encoded ) ? $encoded : '{}';
	}

	/**
	 * Instancie le DTO depuis un tableau associatif.
	 *
	 * @param array<string, mixed> $data Données brutes.
	 */
	public static function from_array( array $data ): self {
		$content_type = isset( $data['content_type'] ) && is_string( $data['content_type'] ) ? $data['content_type'] : null;
		$charset      = isset( $data['charset'] ) && is_string( $data['charset'] ) ? $data['charset'] : null;
		$from_email   = isset( $data['from_email'] ) && is_string( $data['from_email'] ) ? $data['from_email'] : null;
		$from_name    = isset( $data['from_name'] ) && is_string( $data['from_name'] ) ? $data['from_name'] : null;

		return new self(
			content_type: $content_type,
			charset: $charset,
			from_email: $from_email,
			from_name: $from_name,
		);
	}

	/**
	 * Instancie le DTO depuis une chaîne JSON.
	 *
	 * @param ?string $json Chaîne JSON stockée en base.
	 */
	public static function from_json( ?string $json ): self {
		if ( empty( $json ) ) {
			return new self();
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return new self();
		}

		/** @var array<string, mixed> $decoded */
		return self::from_array( $decoded );
	}
}
