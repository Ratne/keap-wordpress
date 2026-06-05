<?php
/**
 * Cifratura/hashing dei segreti.
 *
 * @package KeapConnect
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cifratura reversibile (sodium/openssl) e hashing one-way dei segreti.
 *
 * La chiave NON sta nel database: viene da KC_ENCRYPTION_KEY (wp-config.php)
 * o, in fallback, derivata dai salt di WordPress. In questo modo un dump del
 * solo database non e' sufficiente a decifrare i segreti.
 */
class KC_Crypto {

	const ENC_PREFIX_SODIUM = 'kcv1:';
	const ENC_PREFIX_SSL    = 'kcs1:';
	const HASH_PREFIX       = 'kch1:';

	/**
	 * Ritorna la chiave binaria a 32 byte.
	 *
	 * @return string
	 */
	public static function key() {
		if ( defined( 'KC_ENCRYPTION_KEY' ) && KC_ENCRYPTION_KEY ) {
			$k = (string) KC_ENCRYPTION_KEY;
			if ( preg_match( '/^[0-9a-fA-F]{64}$/', $k ) ) {
				return hex2bin( $k );
			}
			return hash( 'sha256', 'kc|' . $k, true );
		}

		// Fallback: deriva dai salt di WordPress.
		$material = '';
		foreach ( array( 'AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' ) as $const ) {
			if ( defined( $const ) ) {
				$material .= constant( $const );
			}
		}
		if ( '' === $material && function_exists( 'wp_salt' ) ) {
			$material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		}
		if ( '' === $material ) {
			$material = 'kc-insecure-fallback-key';
		}
		return hash( 'sha256', 'kc|' . $material, true );
	}

	/**
	 * Cifra un testo (reversibile). Ritorna stringa con prefisso, o '' se vuoto.
	 *
	 * @param string $plaintext Testo in chiaro.
	 * @return string
	 */
	public static function encrypt( $plaintext ) {
		return self::encrypt_with( $plaintext, self::key() );
	}

	/**
	 * Cifra usando una chiave esplicita (utile per la rotazione della chiave).
	 *
	 * @param string $plaintext Testo in chiaro.
	 * @param string $key       Chiave binaria a 32 byte.
	 * @return string
	 */
	public static function encrypt_with( $plaintext, $key ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			return self::ENC_PREFIX_SODIUM . base64_encode( $nonce . $cipher );
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false === $cipher ) {
				return $plaintext;
			}
			return self::ENC_PREFIX_SSL . base64_encode( $iv . $tag . $cipher );
		}

		// Nessuna libreria di cifratura: meglio non perdere il dato.
		return $plaintext;
	}

	/**
	 * Decifra un valore. Se non e' cifrato lo ritorna invariato (legacy plaintext).
	 *
	 * @param string $stored Valore salvato.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		$key = self::key();

		if ( 0 === strpos( $stored, self::ENC_PREFIX_SODIUM ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return '';
			}
			$raw = base64_decode( substr( $stored, strlen( self::ENC_PREFIX_SODIUM ) ), true );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
			return false === $plain ? '' : $plain;
		}

		if ( 0 === strpos( $stored, self::ENC_PREFIX_SSL ) ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return '';
			}
			$raw = base64_decode( substr( $stored, strlen( self::ENC_PREFIX_SSL ) ), true );
			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return '';
			}
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? '' : $plain;
		}

		// Non cifrato (valore legacy).
		return $stored;
	}

	/**
	 * Indica se un valore e' gia' cifrato.
	 *
	 * @param string $value Valore.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		$value = (string) $value;
		return 0 === strpos( $value, self::ENC_PREFIX_SODIUM ) || 0 === strpos( $value, self::ENC_PREFIX_SSL );
	}

	/**
	 * Hash one-way (HMAC con chiave) di un token. Irreversibile.
	 *
	 * @param string $plaintext Token in chiaro.
	 * @return string
	 */
	public static function hash_token( $plaintext ) {
		return self::hash_token_with( $plaintext, self::key() );
	}

	/**
	 * Hash one-way con chiave esplicita.
	 *
	 * @param string $plaintext Token in chiaro.
	 * @param string $key       Chiave binaria.
	 * @return string
	 */
	public static function hash_token_with( $plaintext, $key ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}
		return self::HASH_PREFIX . hash_hmac( 'sha256', $plaintext, $key );
	}

	/**
	 * Verifica un token in chiaro contro l'hash salvato (constant-time).
	 *
	 * @param string $plaintext Token fornito.
	 * @param string $hash      Hash salvato.
	 * @return bool
	 */
	public static function verify_token( $plaintext, $hash ) {
		$hash      = (string) $hash;
		$plaintext = (string) $plaintext;
		if ( '' === $hash || '' === $plaintext ) {
			return false;
		}
		return hash_equals( $hash, self::hash_token( $plaintext ) );
	}
}
