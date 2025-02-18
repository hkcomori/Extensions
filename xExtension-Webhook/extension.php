<?php

declare(strict_types=1);

include __DIR__ . "/request.php";

enum BODY_TYPE: string {
	case JSON = "json";
	case FORM = "form";
}

enum HTTP_METHOD: string {
	case GET = "GET";
	case POST = "POST";
	case PUT = "PUT";
	case DELETE = "DELETE";
	case PATCH = "PATCH";
	case OPTIONS = "OPTIONS";
	case HEAD = "HEAD";
}

final class WebhookExtension extends Minz_Extension {
	public bool $logsEnabled = false;

	public HTTP_METHOD $webhook_method = HTTP_METHOD::POST;
	public BODY_TYPE $webhook_body_type = BODY_TYPE::JSON;

	public string $webhook_url = "http://<WRITE YOUR URL HERE>";

	/** @var string[] */
	public $webhook_headers = ["User-Agent: FreshRSS", "Content-Type: application/x-www-form-urlencoded"];
	public string $webhook_body = '{
	"title": "__TITLE__",
	"feed": "__FEED__",
	"url": "__URL__",
	"created": "__DATE_TIMESTAMP__"
}';

	#[\Override]
	public function init(): void {
		$this->registerTranslates();
		$this->registerHook("entry_before_insert", [$this, "processArticle"]);
	}

	#[\Override]
	public function handleConfigureAction(): void {
		$this->registerTranslates();

		if (Minz_Request::isPost()) {
			$conf = [
				"keywords" => array_filter(Minz_Request::paramTextToArray("keywords", false), function (string $keyword) {
					return empty($keyword);
				}),
				"search_in_title" => Minz_Request::paramString("search_in_title"),
				"search_in_feed" => Minz_Request::paramString("search_in_feed"),
				"search_in_authors" => Minz_Request::paramString("search_in_authors"),
				"search_in_content" => Minz_Request::paramString("search_in_content"),
				"mark_as_read" => (bool) Minz_Request::paramString("mark_as_read"),
				"ignore_updated" => (bool) Minz_Request::paramString("ignore_updated"),

				"webhook_url" => Minz_Request::paramString("webhook_url"),
				"webhook_method" => Minz_Request::paramString("webhook_method"),
				"webhook_headers" => array_filter(Minz_Request::paramTextToArray("webhook_headers", false), function (string $keyword) {
					return empty($keyword);
				}),
				"webhook_body" => html_entity_decode(Minz_Request::paramString("webhook_body")),
				"webhook_body_type" => Minz_Request::paramString("webhook_body_type"),
				"enable_logging" => (bool) Minz_Request::paramString("enable_logging"),
			];
			$this->setSystemConfiguration($conf);
			$this->logsEnabled = $conf["enable_logging"];

			_LOG($this->logsEnabled, "saved config: ✅ " . json_encode($conf));

			if (Minz_Request::paramString("test_request") !== "") {
				try {
					sendReq(
						$conf["webhook_url"],
						$conf["webhook_method"],
						$conf["webhook_body_type"],
						$conf["webhook_body"],
						$conf["webhook_headers"],
						$conf["enable_logging"],
					);
				} catch (Throwable $err) {
					_LOG_ERR($this->logsEnabled, "Error when sending TEST webhook. " . $err);
				}
			}
		}
	}

	public function processArticle(FreshRSS_Entry $entry): mixed {
		if (!is_null($this->getSystemConfigurationValue("ignore_updated")) && $entry->isUpdated()) {
			_LOG(true, "⚠️ ignore_updated: " . $entry->link() . " ♦♦ " . $entry->title());
			return $entry;
		}

		$searchInTitle = !is_null($this->getSystemConfigurationValue("search_in_title"));
		$searchInFeed = !is_null($this->getSystemConfigurationValue("search_in_feed"));
		$searchInAuthors = !is_null($this->getSystemConfigurationValue("search_in_authors"));
		$searchInContent = !is_null($this->getSystemConfigurationValue("search_in_content"));

		/** @var string[] */
		$patterns = $this->getSystemConfigurationValue("keywords") ?? [];
		$markAsRead = !is_null($this->getSystemConfigurationValue("mark_as_read"));
		$logsEnabled = (bool) ($this->getSystemConfigurationValue("enable_logging") ?? false);
		$this->logsEnabled = (bool) ($this->getSystemConfigurationValue("enable_logging") ?? false);

		//-- do check keywords: ---------------------------
		if (!is_array($patterns)) {
			_LOG_ERR($logsEnabled, "❗️ No keywords defined in Webhook extension settings.");
			return null;
		}

		$title = "❗️NOT INITIALIZED";
		$link = "❗️NOT INITIALIZED";
		$additionalLog = "";

		$title = $entry->title();
		$link = $entry->link();
		foreach ($patterns as $pattern) {
			if ($searchInTitle && self::isPatternFound("/{$pattern}/", $title)) {
				_LOG($logsEnabled, "matched item by title ✔️ \"{$title}\" ❖ link: {$link}");
				$additionalLog = "✔️ matched item with pattern: /{$pattern}/ ❖ title \"{$title}\" ❖ link: {$link}";
				break;
			}
			if ($searchInFeed && (is_object($entry->feed()) && self::isPatternFound("/{$pattern}/", $entry->feed()->name()))) {
				_LOG($logsEnabled, "matched item with pattern: /{$pattern}/ ❖ feed \"{$entry->feed()->name()}\", (title: \"{$title}\") ❖ link: {$link}");
				$additionalLog = "✔️ matched item with pattern: /{$pattern}/ ❖ feed \"{$entry->feed()->name()}\", (title: \"{$title}\") ❖ link: {$link}";
				break;
			}
			if ($searchInAuthors && self::isPatternFound("/{$pattern}/", $entry->authors(true))) {
				_LOG($logsEnabled, "✔️ matched item with pattern: /{$pattern}/ ❖ authors \"{$entry->authors(true)}\", (title: {$title}) ❖ link: {$link}");
				$additionalLog = "✔️ matched item with pattern: /{$pattern}/ ❖ authors \"{$entry->authors(true)}\", (title: {$title}) ❖ link: {$link}";
				break;
			}
			if ($searchInContent && self::isPatternFound("/{$pattern}/", $entry->content())) {
				_LOG($logsEnabled, "✔️ matched item with pattern: /{$pattern}/ ❖ content (title: \"{$title}\") ❖ link: {$link}");
				$additionalLog = "✔️ matched item with pattern: /{$pattern}/ ❖ content (title: \"{$title}\") ❖ link: {$link}";
				break;
			}
		}

		if ($markAsRead) {
			$entry->_isRead($markAsRead);
		}

		$this->sendArticle($entry, $additionalLog);

		return $entry;
	}

	private function sendArticle(FreshRSS_Entry $entry, string $additionalLog = ""): void {
		$feed = $entry->feed() ?? FreshRSS_Feed::default();

		/** @var string */
		$bodyStr = $this->getSystemConfigurationValue("webhook_body") ?? "";

		$bodyStr = str_replace("__TITLE__", self::toSafeJsonStr($entry->title()), $bodyStr);
		$bodyStr = str_replace("__FEED__", self::toSafeJsonStr($feed->name()), $bodyStr);
		$bodyStr = str_replace("__URL__", self::toSafeJsonStr($entry->link()), $bodyStr);
		$bodyStr = str_replace("__CONTENT__", self::toSafeJsonStr($entry->content()), $bodyStr);
		$bodyStr = str_replace("__DATE__", self::toSafeJsonStr($entry->date()), $bodyStr);
		$bodyStr = str_replace("__DATE_TIMESTAMP__", self::toSafeJsonStr($entry->date(true)), $bodyStr);
		$bodyStr = str_replace("__AUTHORS__", self::toSafeJsonStr($entry->authors(true)), $bodyStr);
		$bodyStr = str_replace("__TAGS__", self::toSafeJsonStr($entry->tags(true)), $bodyStr);

		/** @var string */
		$url = $this->getSystemConfigurationValue("webhook_url") ?? "";
		/** @var string */
		$method = $this->getSystemConfigurationValue("webhook_method") ?? "";
		/** @var string */
		$body_type = $this->getSystemConfigurationValue("webhook_body_type") ?? "";
		/** @var array<int, string> */
		$headers = $this->getSystemConfigurationValue("webhook_headers") ?? [];
		$enable_logging = (bool) ($this->getSystemConfigurationValue("enable_logging") ?? false);

		try {
			sendReq(
				$url,
				$method,
				$body_type,
				$bodyStr,
				$headers,
				$enable_logging,
				$additionalLog,
			);
		} catch (Throwable $err) {
			_LOG_ERR($this->logsEnabled, "ERROR in sendArticle: {$err}");
		}
	}

	private function toSafeJsonStr(string|int $str): string {
		/** @var string */
		$output = "";
		if (is_numeric($str)) {
			$output = "{$str}";
		} else {
			$output = str_replace("/\"/", "", html_entity_decode($output));
		}
		return $output;
	}

	private function isPatternFound(string $pattern, string $text): bool {
		if (empty($text) || empty($pattern)) {
			return false;
		}
		if (1 === preg_match($pattern, $text)) {
			return true;
		} elseif (strpos($text, $pattern) !== false) {
			return true;
		}
		return false;
	}

	public function getKeywordsData(): string {
		/** @var string[] */
		$keywords = $this->getSystemConfigurationValue("keywords") ?? [];
		return implode(PHP_EOL, $keywords);
	}

	public function getWebhookHeaders(): string {
		/** @var string[] */
		$headers = $this->getSystemConfigurationValue("webhook_headers") ?? $this->webhook_headers;
		return implode(
			PHP_EOL,
			$headers,
		);
	}

	public function getWebhookUrl(): string {
		/** @var string */
		$url = $this->getSystemConfigurationValue("webhook_url") ?? $this->webhook_url;
		return $url;
	}

	public function getWebhookBody(): string {
		/** @var string|null */
		$body = $this->getSystemConfigurationValue("webhook_body");
		return is_null($body) || $body === "" ? $this->webhook_body : $body;
	}

	public function getWebhookBodyType(): string {
		/** @var string */
		$body_type = $this->getSystemConfigurationValue("webhook_body_type") ?? $this->webhook_body_type;
		return $body_type;
	}
}

function _LOG(bool $logEnabled, string $data): void {
	if ($logEnabled) {
		try {
			Minz_Log::warning("[WEBHOOK] " . $data);
		} catch (Minz_PermissionDeniedException) {
			// Ignore this exception
		}
	}
}

function _LOG_ERR(bool $logEnabled, string $data): void {
	if ($logEnabled) {
		try {
			Minz_Log::error("[WEBHOOK] ❌ " . $data);
		} catch (Minz_PermissionDeniedException) {
			// Ignore this exception
		}
	}
}
