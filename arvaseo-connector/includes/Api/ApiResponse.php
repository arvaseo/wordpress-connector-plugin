<?php
/**
 * A response from the Arva SEO API.
 *
 * @package ArvaSEO\Connector
 */

namespace ArvaSEO\Connector\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Status, decoded JSON body and, on errors, the stable error code and a safe message.
 */
final class ApiResponse {

	/**
	 * Constructor.
	 *
	 * @param int                  $status      HTTP status (0 for network errors).
	 * @param array<string, mixed> $body        Decoded JSON.
	 * @param string|null          $error_code  Stable error code.
	 * @param string|null          $message     Safe message for administrators.
	 * @param int|null             $retry_after Seconds from Retry-After.
	 */
	public function __construct(
		public readonly int $status,
		public readonly array $body,
		public readonly ?string $error_code = null,
		public readonly ?string $message = null,
		public readonly ?int $retry_after = null
	) {}

	/**
	 * 2xx.
	 */
	public function ok(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Worth retrying later (network, 429, 5xx).
	 */
	public function retryable(): bool {
		return 0 === $this->status || 429 === $this->status || $this->status >= 500;
	}

	/**
	 * The "data" member, if any.
	 *
	 * @return array<string, mixed>|array<int, mixed>
	 */
	public function data(): array {
		$data = $this->body['data'] ?? $this->body;
		return is_array( $data ) ? $data : array();
	}
}
