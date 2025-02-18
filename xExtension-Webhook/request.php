<?php

/**
 * Summary of sendReq
 * @param string $url
 * @param string $method
 * @param string $bodyType
 * @param string $body
 * @param array<int, string> $headers
 * @param bool $logEnabled
 * @param string $additionalLog
 * @throws Throwable
 */
function sendReq(
	string $url,
	string $method,
	string $bodyType,
	string $body,
	array $headers = [],
	bool $logEnabled = true,
	string $additionalLog = "",
): void {
	// LOG_WARN($logEnabled, "> sendReq ⏩ ( {$method}: " . $url . ", " . $bodyType . ", " . json_encode($body));

	/** @var CurlHandle $ch */
	$ch = curl_init($url);
	try {
		// ----------------------[ HTTP Method: ]-----------------------------------

		if ($method === "POST") {
			curl_setopt($ch, CURLOPT_POST, true);
		}
		if ($method === "PUT") {
			curl_setopt($ch, CURLOPT_PUT, true);
		}
		if ($method === "GET") {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
		}
		if ($method === "DELETE") {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
		}

		// ----------------------[ HTTP Body: ]-----------------------------------

		/** @var string */
		$bodyToSend = "";
		try {
			// $bodyObject = json_decode(json_encode($body ?? ""), true, 64, JSON_THROW_ON_ERROR);
			$bodyObject = json_decode(($body), true, 256, JSON_THROW_ON_ERROR);

			// LOG_WARN($logEnabled, "bodyObject: " . json_encode($bodyObject));

			if ($bodyType === "json" && !is_null($bodyObject) && is_object(($bodyObject))) {
				$bodyToSend = json_encode($bodyObject);
				if (!is_string($bodyToSend)) {
					LOG_ERR($logEnabled, "ERROR during parsing HTTP Body, json encode failed | Body: {$body}");
					return;
				}
				// LOG_WARN($logEnabled, "> json_encode ⏩: {$bodyToSend}");
			} else if ($bodyType === "form" && (is_array($bodyObject) || is_object(($bodyObject)))) {
				$bodyToSend = http_build_query($bodyObject);
				// LOG_WARN($logEnabled, "> http_build_query ⏩: " . $body);
			} else {
				LOG_ERR($logEnabled, "ERROR during parsing HTTP Body, type mismatch | BodyType: {$bodyType}, Body: {$body}");
				return;
			}

			if (!empty($body) && $method !== "GET") {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyToSend);
			}
		} catch (Throwable $err) {
			LOG_ERR($logEnabled, "ERROR during parsing HTTP Body, ERROR: {$err} | Body: {$body}");
			LOG_ERR($logEnabled, "ERROR during parsing HTTP Body, ERROR: " . json_encode($err) . "| Body: {$body}");
			throw $err;
		}

		// LOG_WARN($logEnabled, "> sendReq ⏩ {$method}: {$url}  ♦♦  {$bodyType}  ♦♦  {$bodyToSend}");

		// ----------------------[ HTTP Headers: ]-----------------------------------

		if (empty($headers)) {
			if ($bodyType === "form") {
				array_push($headers, "Content-Type: application/x-www-form-urlencoded");
			}
			if ($bodyType === "json") {
				array_push($headers, "Content-Type: application/json");
			}
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		LOG_WARN(
			$logEnabled,
			"{$additionalLog} ♦♦ sendReq ⏩ {$method}: " . urldecode($url)
				. " ♦♦  {$bodyType}  ♦♦  " . str_replace("\/", "/", $bodyToSend)
				. "  ♦♦  " . json_encode($headers)
		);

		// ----------------------[ 🚀 SEND Request! ]-----------------------------------

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($ch);
		$info = curl_getinfo($ch);
		if (!is_array($info)) {
			LOG_WARN($logEnabled, 'curl_getinfo() failed in ' . __METHOD__);
			return;
		}

		// ----------------------[ Check for errors: ]-----------------------------------

		if (curl_errno($ch)) {
			$error = curl_error($ch);
			LOG_ERR($logEnabled, "< ERROR: " . $error);
		} else {
			LOG_WARN($logEnabled, "< Response ✅ (" . strval($info["http_code"]) . ") response:" . $response);
		}
	} catch (Throwable $err) {
		LOG_ERR($logEnabled, "< ERROR in sendReq: " . $err . " ♦♦ body: {$body} ♦♦");
	} finally {
		// Close the cURL session
		curl_close($ch);
	}
}

/**
 * Summary of LOG_WARN
 * @param bool $logEnabled
 * @param string $data
 * @throws Minz_PermissionDeniedException
 */
function LOG_WARN(bool $logEnabled, string $data): void {
	if ($logEnabled) {
		Minz_Log::warning("[WEBHOOK] " . $data);
	}
}

/**
 * Summary of LOG_ERR
 * @param bool $logEnabled
 * @param string $data
 * @throws Minz_PermissionDeniedException
 */
function LOG_ERR(bool $logEnabled, string $data): void {
	if ($logEnabled) {
		Minz_Log::error("[WEBHOOK]❌ " . $data);
	}
}
