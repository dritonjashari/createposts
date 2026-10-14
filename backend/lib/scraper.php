<?php
// ---------------------------------------------------------------------------
// "Import from URL" on the Publish page: fetch an article from another
// website and pull out its title, body text and featured image — following
// the article's own pagination (/2/, ?page=2, "Next page" links) so a story
// split over several pages comes back as one piece of text.
//
// Fetching is restricted to public http(s) hosts: the URL comes from the
// user, so without this anyone with an account could make the server read
// internal addresses (127.0.0.1 services, the hosting LAN, cloud metadata).
// ---------------------------------------------------------------------------

const SCRAPE_MAX_PAGES      = 30;
const SCRAPE_MAX_HTML_BYTES = 6 * 1024 * 1024;
const SCRAPE_MAX_IMG_BYTES  = 10 * 1024 * 1024;
const SCRAPE_USER_AGENT     = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';


/** True when $ip is a public address (not loopback, private, link-local, reserved...). */
function scrapeIsPublicIp(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}


/**
 * Validate a URL for fetching and resolve its host to one public IP.
 * Returns [scheme, host, port, ip] or null with $error set.
 */
function scrapeResolveTarget(string $url, ?string &$error): ?array
{
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host   = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        $error = 'Please enter a full web address starting with http:// or https://';
        return null;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        $error = 'Web addresses with a username or password are not supported.';
        return null;
    }
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if (!in_array($port, [80, 443, 8080, 8443], true)) {
        $error = 'That web address uses a port that is not allowed.';
        return null;
    }

    $allowPrivate = defined('SCRAPER_ALLOW_PRIVATE_HOSTS') && SCRAPER_ALLOW_PRIVATE_HOSTS;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = @gethostbynamel($host) ?: [];
        if (function_exists('dns_get_record')) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $rec) {
                if (!empty($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
    }
    if (empty($ips)) {
        $error = 'Could not find the website "' . $host . '". Check the address.';
        return null;
    }
    // Every address the name resolves to must be public, otherwise a DNS
    // record mixing a public and a private IP could still reach inside.
    foreach ($ips as $ip) {
        if (!$allowPrivate && !scrapeIsPublicIp($ip)) {
            $error = 'That address points to a private network and cannot be imported.';
            return null;
        }
    }
    $ip = $ips[0];
    foreach ($ips as $candidate) { // prefer IPv4 — some hosts have no IPv6 route
        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = $candidate;
            break;
        }
    }
    return [$scheme, $host, $port, $ip];
}


/**
 * GET a public URL, following up to 5 redirects (each one re-validated).
 * Returns ['body','url','type','status'] or null with $error set.
 */
function scrapeHttpGet(string $url, int $maxBytes, ?string &$error, string $accept = 'text/html,application/xhtml+xml,*/*;q=0.8'): ?array
{
    return scrapeRequest($url, $maxBytes, $error, $accept, null, 25);
}


/**
 * Stream a public URL straight to $destPath (for large media such as videos,
 * which must not be held in memory). Same host checks as scrapeHttpGet().
 */
function scrapeDownloadToFile(string $url, string $destPath, int $maxBytes, ?string &$error): bool
{
    $fh = @fopen($destPath, 'wb');
    if ($fh === false) {
        $error = 'Could not create a temporary file for the download.';
        return false;
    }
    $res = scrapeRequest($url, $maxBytes, $error, '*/*', $fh, 600);
    fclose($fh);
    return $res !== null;
}


/** Shared fetch loop: body kept in memory, or written to $sink (an open file handle). */
function scrapeRequest(string $url, int $maxBytes, ?string &$error, string $accept, $sink, int $timeout): ?array
{
    for ($hop = 0; $hop <= 5; $hop++) {
        $target = scrapeResolveTarget($url, $error);
        if ($target === null) {
            return null;
        }
        [, $host, $port, $ip] = $target;

        $body    = '';
        $written = 0;
        $tooBig  = false;
        if ($sink !== null) { // a redirect hop may already have written a body
            ftruncate($sink, 0);
            rewind($sink);
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            // Connect to the IP we just checked, so DNS can't be switched
            // to a private address between the check and the request.
            CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => SCRAPE_USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: ' . $accept, 'Accept-Language: en-US,en;q=0.9'],
            CURLOPT_HEADER         => false,
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body, &$written, &$tooBig, $maxBytes, $sink): int {
                if ($written + strlen($chunk) > $maxBytes) {
                    $tooBig = true;
                    return 0; // abort the transfer
                }
                $written += strlen($chunk);
                if ($sink !== null) {
                    return (int) fwrite($sink, $chunk);
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type     = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($tooBig) {
            $error = 'The page or image is too large to import.';
            return null;
        }
        if ($status >= 300 && $status < 400 && $location !== '') {
            $url = $location;
            continue;
        }
        if ($status === 0) {
            $error = 'Could not connect to the website' . ($curlErr !== '' ? ' (' . $curlErr . ')' : '') . '.';
            return null;
        }
        if ($status >= 400) {
            $error = $status === 403 || $status === 401
                ? 'The website refused the request (HTTP ' . $status . '). It may block automatic imports.'
                : 'The website returned an error (HTTP ' . $status . ').';
            return null;
        }
        return ['body' => $body, 'url' => $url, 'type' => $type, 'status' => $status];
    }
    $error = 'The web address redirects too many times.';
    return null;
}


/** Make $href absolute against $base (handles //host, /path, ../path, ?query, #frag). */
function scrapeAbsoluteUrl(string $href, string $base): string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || preg_match('#^(javascript|mailto|tel|data):#i', $href)) {
        return '';
    }
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    $b = parse_url($base);
    $scheme = $b['scheme'] ?? 'https';
    $host   = ($b['host'] ?? '') . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($href, '//')) {
        return $scheme . ':' . $href;
    }
    if ($href[0] === '#') {
        return '';
    }
    if ($href[0] === '?') {
        return $scheme . '://' . $host . ($b['path'] ?? '/') . $href;
    }
    if ($href[0] === '/') {
        $path = $href;
    } else {
        $dir  = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
        $path = $dir . $href;
    }
    // Resolve ./ and ../ segments.
    $query = '';
    if (($q = strpos($path, '?')) !== false) {
        $query = substr($path, $q);
        $path  = substr($path, 0, $q);
    }
    $out = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '..') {
            array_pop($out);
        } elseif ($seg !== '.') {
            $out[] = $seg;
        }
    }
    return $scheme . '://' . $host . '/' . ltrim(implode('/', $out), '/') . $query;
}


/** Parse HTML into a DOMDocument, converting the page's charset to UTF-8. */
function scrapeLoadDom(string $html, string $contentType): DOMDocument
{
    $charset = '';
    if (preg_match('/charset=["\']?([\w-]+)/i', $contentType, $m)) {
        $charset = $m[1];
    } elseif (preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($html, 0, 4096), $m)) {
        $charset = $m[1];
    }
    if ($charset !== '' && strcasecmp($charset, 'utf-8') !== 0 && strcasecmp($charset, 'utf8') !== 0) {
        $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
        if (is_string($converted) && $converted !== '') {
            $html = $converted;
        }
    }
    // Drop the page's own charset declaration — we prepend ours so libxml
    // reads the (now UTF-8) bytes correctly.
    $html = preg_replace('/<meta[^>]+charset=[^>]*>/i', '', $html);

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $doc;
}


function scrapeMeta(DOMXPath $xp, array $names): string
{
    foreach ($names as $name) {
        foreach ($xp->query('//meta[@property="' . $name . '" or @name="' . $name . '"]/@content') as $attr) {
            $v = trim($attr->nodeValue);
            if ($v !== '') {
                return $v;
            }
        }
    }
    return '';
}


/** XPath for "element has CSS class $cls". */
function scrapeClassXPath(string $cls): string
{
    return "contains(concat(' ', normalize-space(@class), ' '), ' " . $cls . " ')";
}


function scrapeTextLength(DOMNode $node): int
{
    return mb_strlen(trim(preg_replace('/\s+/u', ' ', $node->textContent)));
}


/** Pick the element holding the article body. */
function scrapeFindContentNode(DOMXPath $xp): ?DOMNode
{
    $selectors = [
        '//*[@itemprop="articleBody"]',
        '//*[' . scrapeClassXPath('entry-content') . ']',
        '//*[' . scrapeClassXPath('post-content') . ']',
        '//*[' . scrapeClassXPath('article-content') . ']',
        '//*[' . scrapeClassXPath('article-body') . ']',
        '//*[' . scrapeClassXPath('article__body') . ']',
        '//*[' . scrapeClassXPath('post-body') . ']',
        '//*[' . scrapeClassXPath('post-entry') . ']',
        '//*[' . scrapeClassXPath('td-post-content') . ']',
        '//*[' . scrapeClassXPath('single-content') . ']',
        '//*[' . scrapeClassXPath('story-content') . ']',
        '//*[' . scrapeClassXPath('content-body') . ']',
        '//*[' . scrapeClassXPath('blog-content') . ']',
        '//article',
        '//main',
    ];
    $weak = null; // a known article container holding only a little text
    $weakLen = 0;
    foreach ($selectors as $sel) {
        $best = null;
        $bestLen = 0;
        foreach ($xp->query($sel) as $node) {
            $len = 0;
            foreach ($xp->query('.//p', $node) as $p) {
                $len += scrapeTextLength($p);
            }
            if ($len === 0) { // text split with <br>/<div> instead of <p>
                $len = intdiv(scrapeTextLength($node), 2);
            }
            if ($len > $bestLen) {
                $best = $node;
                $bestLen = $len;
            }
        }
        if ($best !== null && $bestLen >= 150) {
            return $best;
        }
        if ($weak === null && $best !== null && $bestLen >= 1 && !in_array($sel, ['//article', '//main'], true)) {
            $weak = $best;
            $weakLen = $bestLen;
        }
    }

    // Fallback: the element whose direct <p> children hold the most text —
    // ignoring sidebars, footers, menus and comment areas.
    $scores = [];
    $nodes  = [];
    $outside = "ancestor::aside or ancestor::footer or ancestor::nav or ancestor::header"
        . " or ancestor::*[contains(@class,'sidebar') or contains(@class,'widget') or contains(@class,'comment') or contains(@class,'author')"
        . " or contains(@class,'related') or contains(@id,'sidebar') or contains(@id,'comments')]";
    foreach ($xp->query('//p[not(' . $outside . ')]') as $p) {
        $len = scrapeTextLength($p);
        if ($len < 25 || !$p->parentNode) {
            continue;
        }
        $parent = $p->parentNode;
        $key = spl_object_id($parent);
        $nodes[$key]  = $parent;
        $scores[$key] = ($scores[$key] ?? 0) + $len;
    }
    arsort($scores);
    $top = empty($scores) ? null : array_key_first($scores);
    // A real article container (entry-content, articleBody...) beats loose
    // paragraphs elsewhere unless those clearly hold far more text.
    if ($weak !== null && ($top === null || $scores[$top] < max(300, 5 * $weakLen))) {
        return $weak;
    }
    return $top === null ? null : $nodes[$top];
}


/** Remove scripts, ads, share bars, related posts, etc. from inside $root. */
function scrapeStripNoise(DOMXPath $xp, DOMNode $root): void
{
    // Paragraph text, not all text: share bars and menus are mostly link
    // labels, so on a short article they can outweigh the story itself.
    $paraLen = static function (DOMNode $n) use ($xp): int {
        $len = $n instanceof DOMElement && $n->tagName === 'p' ? scrapeTextLength($n) : 0;
        foreach ($xp->query('.//p', $n) as $p) {
            $len += scrapeTextLength($p);
        }
        return $len;
    };
    $rootLen = max(1, $paraLen($root));
    $remove = [];
    foreach ($xp->query('.//script | .//style | .//noscript | .//iframe | .//form | .//button | .//svg | .//nav | .//aside | .//footer | .//figcaption | .//select | .//input | .//textarea | .//ins | .//object | .//embed | .//video | .//audio', $root) as $n) {
        $remove[] = $n;
    }

    // Author / date markup and ad slots identified by standard attributes.
    $marked = './/address | .//*[@itemprop="author" or @itemprop="datePublished" or @itemprop="dateModified" or @itemprop="dateCreated" or @rel="author"]'
        . ' | .//*[starts-with(@id, "div-gpt-ad") or starts-with(@id, "google_ads") or @data-ad-slot or @data-ad-client or @data-adunit or @data-ad or @data-google-query-id]'
        . ' | .//*[contains(translate(@aria-label, "ADVERTISEMNT", "advertisemnt"), "advertisement")]';
    foreach ($xp->query($marked, $root) as $n) {
        if ($paraLen($n) < $rootLen * 0.4) {
            $remove[] = $n;
        }
    }
    // <time> is a post/update date — unless it sits inside a real sentence.
    foreach ($xp->query('.//time', $root) as $n) {
        $block = $n->parentNode;
        while ($block instanceof DOMElement && in_array($block->tagName, ['span', 'a', 'strong', 'em', 'b', 'i', 'small'], true)) {
            $block = $block->parentNode;
        }
        if (!$block instanceof DOMElement || $block->tagName !== 'p' || scrapeTextLength($block) - scrapeTextLength($n) < 40) {
            $remove[] = $n;
        }
    }

    // Class / id / test-id names that mean "not the story": meta (author,
    // date), ads, share bars, related posts, captions and credits, players...
    $noise = '/(^|[\s_-])(entry-meta|post-meta|article-meta|meta|byline|posted-on|posted-by|author|authors|author-name|writer|vcard'
        . '|date|dates|time|timestamp|published|updated|post-date|entry-date|publish-date|article-date|dateline|reading-time|read-time'
        . '|share|sharing|sharedaddy|social|related|relatedposts|newsletter|subscribe|comments?|respond'
        . '|advert|advertisement|advertising|adv|ads|ad|adsbygoogle|adunit|adslot|dfp|gpt|ezoic|adthrive|mediavine|mv-ad-box|code-block'
        . '|sponsor|sponsored|promo|popup|modal|breadcrumbs?|author-box|author-bio|post-tags|tags|sidebar|widget|cookie'
        . '|outbrain|taboola|mgid|revcontent|pagination|page-links|post-navigation|nav-links|wp-block-buttons|read-more|readmore'
        . '|jp-relatedposts|yarpp|crp_related|caption|wp-caption-text|credit|credits|image-source|photo-credit|image-credit|video|player|jwplayer)([\s_-]|$)/i';
    foreach ($xp->query('.//*[@class or @id or @data-testid or @data-test or @data-qa] | .//header', $root) as $n) {
        if (!$n instanceof DOMElement) {
            continue;
        }
        $attr = $n->getAttribute('class') . ' ' . $n->getAttribute('id') . ' ' . $n->getAttribute('data-testid')
            . ' ' . $n->getAttribute('data-test') . ' ' . $n->getAttribute('data-qa');
        if ($n->tagName === 'header' || preg_match($noise, $attr)) {
            // Never throw away most of the article because of one odd class.
            if ($paraLen($n) < $rootLen * 0.4) {
                $remove[] = $n;
            }
        }
    }

    // Blocks made only of links: breadcrumbs ("Home › Viral"), "related
    // articles" lists, "Read also: ..." lines.
    foreach ($xp->query('.//ul | .//ol | .//div | .//section | .//p | .//table', $root) as $n) {
        $textLen = scrapeTextLength($n);
        if ($textLen === 0 || $paraLen($n) >= $rootLen * 0.4) {
            continue;
        }
        $linkLen = 0;
        foreach ($xp->query('.//a', $n) as $a) {
            $linkLen += scrapeTextLength($a);
        }
        if ($linkLen > 0 && $linkLen >= $textLen * 0.8) {
            $remove[] = $n;
        }
    }
    // Pagination / "continue" links written as plain text links.
    foreach ($xp->query('.//a', $root) as $a) {
        if (preg_match('/^\s*(next|next page|previous|prev|previous page|continue|continue reading|read more|go to page \d+|page \d+|\d{1,2}|›|»|«|‹|>>|<<|→|←)\s*$/iu', $a->textContent)) {
            $remove[] = $a;
        }
    }
    foreach ($remove as $n) {
        if ($n->parentNode) {
            $n->parentNode->removeChild($n);
        }
    }
}


/** Convert the article element to plain text: one paragraph per block, blank line between. */
function scrapeNodeToText(DOMDocument $doc, DOMNode $root): string
{
    $html = '';
    foreach ($root->childNodes as $child) {
        $html .= $doc->saveHTML($child);
    }
    $html = preg_replace('/[\r\n\t]+/', ' ', $html);
    $html = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html);
    $html = preg_replace('/<li\b[^>]*>/i', "\n\n• ", $html);
    $html = preg_replace('/<\/?(p|div|section|article|header|h[1-6]|li|ul|ol|blockquote|pre|table|tr|figure|dl|dt|dd|hr)\b[^>]*>/i', "\n\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\u{00A0}", ' ', $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/ *\n */', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    // "• " left alone on its line by an empty <li>.
    $text = preg_replace('/^•\s*$/m', '', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}


/**
 * Author names the page declares about itself (meta tags, JSON-LD, author
 * links), so "By Jane Doe" / "Jane Doe · 5 min read" lines can be removed
 * precisely even when the site's markup gives no other clue.
 */
function scrapeCollectAuthors(DOMXPath $xp): array
{
    $names = [];
    $add = static function ($v) use (&$names): void {
        if (!is_string($v)) {
            return;
        }
        $v = trim(preg_replace('/\s+/u', ' ', html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $v = preg_replace('/^(by|written by|posted by|author:?)\s+/iu', '', $v);
        if (mb_strlen($v) >= 3 && mb_strlen($v) <= 60 && !preg_match('#^(https?:|@|/)#i', $v) && !preg_match('/\d{3,}/', $v)) {
            $names[mb_strtolower($v)] = $v;
        }
    };
    foreach ($xp->query('//meta[@name="author" or @property="article:author" or @name="article:author" or @name="sailthru.author" or @name="parsely-author"]/@content') as $a) {
        $add($a->nodeValue);
    }
    foreach ($xp->query('//*[@itemprop="author"] | //a[@rel="author"] | //*[contains(@class, "author-name")]') as $n) {
        $add($n->textContent);
    }
    $walk = static function ($node) use (&$walk, $add): void {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if ($key === 'author' || $key === 'creator') {
                foreach ((is_array($value) && array_key_exists(0, $value) ? $value : [$value]) as $person) {
                    is_array($person) ? $add($person['name'] ?? null) : $add($person);
                }
            } elseif (is_array($value)) {
                $walk($value);
            }
        }
    };
    foreach ($xp->query('//script[@type="application/ld+json"]') as $s) {
        $walk(json_decode($s->textContent, true));
    }
    return array_values($names);
}


/**
 * True for a paragraph that is page furniture rather than story: a date or
 * time, a byline, an ad label, an image credit, a widget label. Decided by
 * stripping every date/author/meta token — if nothing is left, it was meta.
 * Long paragraphs are never touched, so a sentence like "On May 5, 2026, I
 * married him." always survives.
 */
function scrapeIsJunkParagraph(string $p, array $authors): bool
{
    $t = trim($p);
    if ($t === '') {
        return true;
    }
    if (mb_strlen($t) > 220) {
        return false;
    }

    // Ad labels and "continues below" fillers (any language the big ad networks use).
    if (preg_match('/^[\W_]*(?:advertisements?|advertising|ads?|ad choices|sponsored(?: content| links?| post| stories)?|(?:sponsored|promoted|presented) by [^.!?,]{1,40}'
        . '|promoted(?: content| stories)?|paid (?:content|post|partnership)|partner content|recommended for you'
        . '|(?:story|article|content|text) continues (?:below|after)(?: this)?(?: ad(?:vertisement)?)?|continue reading below(?: this ad)?'
        . '|scroll (?:down )?to continue(?: reading| with content)?|report (?:this )?ad|ad feedback|remove ads'
        . '|reklam|anzeige|werbung|publicit[ée]|publicidad|pubblicit[àa]|reclame|reklama)[\W_]*$/iu', $t)) {
        return true;
    }
    // Player / widget labels left behind as a line of their own.
    if (preg_match('/^(?:close|video|play|play video|watch|share|menu|home|back to top|loading|loading video|skip ad|skip to content|listen|listen to this article)(?:\s*(?:\.\.\.|…))?$/iu', $t)) {
        return true;
    }
    // Breadcrumb trail: "Home › News", "HomeViral".
    if (preg_match('/^(?i:home)(?:\s*[›»>\/|•·]\s*\S|\p{Lu})/u', $t) && count(preg_split('/\s+/', $t)) <= 6) {
        return true;
    }
    // Image credits: "Credit: X/Shutterstock", "... | Source: Facebook/..."
    if (preg_match('/^\(?\s*(?:(?:photo|image|picture|video|illustration)s?\s*)?(?:credits?|source|courtesy|via)\s*:/iu', $t)
        || preg_match('/\|\s*(?:source|credits?|photo|image)\s*:/iu', $t)) {
        return true;
    }
    // Byline without metadata: "By Jane Writer", "Written by John Smith, Staff Writer".
    // A name word is Capitalized (has lowercase letters), so ALL-CAPS story
    // lines like "BY THE TIME I GOT HOME" are never mistaken for a byline.
    $word = '\p{Lu}[\p{L}\'’.\-]*\p{Ll}[\p{L}\'’.\-]*';
    $name = $word . '(?:\s+(?:' . $word . '|\p{Lu}\.|de|da|del|van|von|der|la|le|bin|al)){0,4}';
    if (preg_match('/^(?i:by|written by|posted by|published by|words by|story by|reported by|authored by|text by|edited by|reviewed by|fact[- ]checked by|author)\s*:?\s+'
        . $name . '(?:\s*(?:,|(?i:and)|&)\s*' . $name . ')*(?:\s*[,|·•–—\-]\s*[^.!?"”]{0,80})?$/u', $t)) {
        return true;
    }
    // Job title line under a byline: "Senior Reporter", "Staff Writer".
    if (preg_match('/^(?:(?:senior|staff|contributing|associate|deputy|chief|freelance|guest|lead|managing|news|sports|entertainment|lifestyle|trending|viral|digital)\s+)*(?:reporter|writer|editor|correspondent|journalist|columnist|contributor|author)s?(?:\s+(?:at|for)\s+.{1,40})?$/iu', $t)) {
        return true;
    }

    // Dates, times and known author names: remove them and see what's left.
    $mon = '(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\.?';
    $wd  = '(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday|mon|tues?|wed|thu(?:rs?)?|fri|sat|sun)\.?,?';
    $patterns = [
        '/\b(?:' . $wd . '\s+)?' . $mon . '\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?\b/iu',
        '/\b(?:' . $wd . '\s+)?\d{1,2}(?:st|nd|rd|th)?\s+(?:of\s+)?' . $mon . '(?:,?\s+\d{4})?\b/iu',
        '/\b' . $mon . '\s+\d{4}\b/iu',
        '/\b\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?/u',
        '/\b\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}\b/u',
        '/\b\d{1,2}:\d{2}(?::\d{2})?(?:\s*[ap]\.?\s?m\.?)?/iu',
        '/\b\d{1,2}\s?(?:[ap]\.\s?m\.?|[ap]m\b)/iu',
        '/\b\d+\s*(?:sec(?:ond)?s?|min(?:ute)?s?|h(?:ou)?rs?|hours?|days?|weeks?|months?|years?)\s+ago\b/iu',
        '/\b\d+\s*(?:-\s*)?min(?:ute)?s?\.?\s+read\b/iu',
    ];
    $hit = false;
    $s = $t;
    $bylineWord = preg_match('/\b(?:by|author|posted|written)\b/iu', $t) === 1;
    foreach ($authors as $a) {
        // A one-word author ("Sarah") only counts next to "By"/"Author", so a
        // character's name on its own line in a story is kept.
        if (!str_contains($a, ' ') && !$bylineWord) {
            continue;
        }
        if (mb_stripos($s, $a) !== false) {
            $s = str_ireplace($a, ' ', $s);
            $hit = true;
        }
    }
    foreach ($patterns as $rx) {
        $s = preg_replace($rx, ' ', $s, -1, $count);
        $hit = $hit || $count > 0;
    }
    if (!$hit) {
        return false;
    }
    $s = preg_replace('/\b(?:by|written|posted|published|updated|last|modified|created|edited|reviewed|date|on|at|in|and|reading|read|time|min|mins|minute|minutes|ago|et|est|edt|pt|pst|pdt|ct|cst|cdt|mt|gmt|utc|bst|cet|cest|am|pm)\b/iu', ' ', $s);
    return preg_replace('/[\s\p{P}\p{S}\d]+/u', '', $s) === '';
}


const SCRAPE_PAGE_PARAMS = ['page', 'pg', 'paged', 'pagina', 'seite', 'sayfa', 'part', 'pagenum', 'pn'];

/**
 * Split an article URL into [article identity, page number]. The identity is
 * host + path + query with the page marker removed, so /story/, /story/2/,
 * /story/page/3/ and /story/?page=4 all share one identity, while "next
 * article" links (a different path) never do. Only small numbers count as
 * page markers — /news/48213/ is an article id, not page 48213 — and
 * WordPress's ?p=123 post id is never treated as a page number.
 */
function scrapeSplitPageUrl(string $url): array
{
    $u    = parse_url($url);
    $host = strtolower($u['host'] ?? '');
    $path = rtrim($u['path'] ?? '', '/');
    $page = 1;
    if (preg_match('#^(.+?)/(?:page/)?(\d{1,2})$#i', $path, $m) && (int) $m[2] >= 1 && (int) $m[2] <= SCRAPE_MAX_PAGES + 1) {
        $path = $m[1];
        $page = (int) $m[2];
    }
    parse_str($u['query'] ?? '', $q);
    foreach (SCRAPE_PAGE_PARAMS as $key) {
        if (isset($q[$key]) && is_string($q[$key]) && ctype_digit($q[$key]) && (int) $q[$key] <= SCRAPE_MAX_PAGES + 1) {
            $page = max(1, (int) $q[$key]);
            unset($q[$key]);
        }
    }
    foreach (array_keys($q) as $key) { // tracking parameters don't make a different article
        if (preg_match('/^(utm_\w+|fbclid|gclid|amp|ref|share)$/i', (string) $key)) {
            unset($q[$key]);
        }
    }
    ksort($q);
    return [$host . '|' . strtolower($path) . '|' . http_build_query($q), $page];
}


/** Links on this page that lead to other pages of the same article, as [pageNo => url]. */
function scrapeFindPageLinks(DOMXPath $xp, string $pageUrl, string $articleId): array
{
    $found = [];
    $hrefs = [];
    foreach ($xp->query('//link[@rel="next"]/@href | //a[@rel="next"]/@href') as $attr) {
        $hrefs[] = $attr->nodeValue;
    }
    // Numbered page links and "next" buttons anywhere on the page; the URL
    // check below keeps only those of this same article.
    foreach ($xp->query('//a[@href]') as $a) {
        $txt = trim(preg_replace('/\s+/u', ' ', $a->textContent));
        $cls = $a->getAttribute('class') . ' ' . ($a->parentNode instanceof DOMElement ? $a->parentNode->getAttribute('class') : '');
        if (mb_strlen($txt) <= 40 || preg_match('/page|pagination|next/i', $cls)) {
            $hrefs[] = $a->getAttribute('href');
        }
    }
    foreach ($hrefs as $href) {
        $abs = scrapeAbsoluteUrl($href, $pageUrl);
        if ($abs === '') {
            continue;
        }
        $abs = preg_replace('/#.*$/', '', $abs);
        [$id, $n] = scrapeSplitPageUrl($abs);
        if ($id === $articleId && !isset($found[$n])) {
            $found[$n] = $abs;
        }
    }
    return $found;
}


/** Best image URL from an <img> (lazy-load attributes and srcset included). */
function scrapeImgSrc(DOMElement $img, string $base): string
{
    foreach (['data-src', 'data-lazy-src', 'data-original', 'data-orig-file', 'src'] as $attr) {
        $v = trim($img->getAttribute($attr));
        if ($v !== '' && !str_starts_with($v, 'data:')) {
            return scrapeAbsoluteUrl($v, $base);
        }
    }
    $srcset = $img->getAttribute('data-srcset') ?: $img->getAttribute('srcset');
    if ($srcset !== '') {
        $best = '';
        $bestW = -1;
        foreach (explode(',', $srcset) as $cand) {
            $bits = preg_split('/\s+/', trim($cand));
            $w = isset($bits[1]) ? (int) $bits[1] : 0;
            if (($bits[0] ?? '') !== '' && $w > $bestW) {
                $best = $bits[0];
                $bestW = $w;
            }
        }
        if ($best !== '') {
            return scrapeAbsoluteUrl($best, $base);
        }
    }
    return '';
}


/** Download an image (public hosts only) and return it base64-encoded for the browser. */
function scrapeDownloadImage(string $url, ?string &$error): ?array
{
    $res = scrapeHttpGet($url, SCRAPE_MAX_IMG_BYTES, $error, 'image/avif,image/webp,image/png,image/jpeg,image/*;q=0.8');
    if ($res === null) {
        return null;
    }
    $info = @getimagesizefromstring($res['body']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    if (!isset($allowed[$mime])) {
        $error = 'The featured image is not a JPG, PNG, GIF or WebP picture.';
        return null;
    }
    $name = basename((string) parse_url($res['url'], PHP_URL_PATH));
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($name, PATHINFO_FILENAME));
    $name = trim(substr($name, 0, 80), '-.') ?: 'featured-image';
    return [
        'name'   => $name . '.' . $allowed[$mime],
        'mime'   => $mime,
        'width'  => (int) $info[0],
        'height' => (int) $info[1],
        'base64' => base64_encode($res['body']),
    ];
}


/**
 * Import an article. Returns ['success'=>true, 'title', 'content', 'image'
 * (array|null), 'image_url', 'pages', 'source_url', 'warnings'] or
 * ['success'=>false, 'message'].
 */
function scrapeArticle(string $url): array
{
    $url = trim($url);
    if ($url !== '' && !preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        $url = 'https://' . $url;
    }
    $error = null;
    $first = scrapeHttpGet($url, SCRAPE_MAX_HTML_BYTES, $error);
    if ($first === null) {
        return ['success' => false, 'message' => $error ?: 'Could not load that page.'];
    }
    if ($first['type'] !== '' && !preg_match('#(text/html|application/xhtml|text/plain)#i', $first['type'])) {
        return ['success' => false, 'message' => 'That address is not a web page (it is ' . strtok($first['type'], ';') . ').'];
    }
    $firstUrl = $first['url'];
    $doc = scrapeLoadDom($first['body'], $first['type']);
    $xp  = new DOMXPath($doc);

    // ---- title ----
    $siteName = scrapeMeta($xp, ['og:site_name']);
    $title = scrapeMeta($xp, ['og:title', 'twitter:title']);
    if ($title === '') {
        $h1 = $xp->query('//article//h1 | //h1')->item(0);
        $title = $h1 ? trim($h1->textContent) : '';
    }
    if ($title === '') {
        $t = $xp->query('//title')->item(0);
        $title = $t ? trim($t->textContent) : '';
    }
    $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($siteName !== '') {
        $title = preg_replace('/\s*[|\-–—:]\s*' . preg_quote($siteName, '/') . '\s*$/iu', '', $title);
    }

    // Read before any cleanup removes the author markup.
    $authors = scrapeCollectAuthors($xp);

    // ---- featured image ----
    $imageUrl = scrapeMeta($xp, ['og:image:secure_url', 'og:image', 'twitter:image', 'twitter:image:src']);
    $imageUrl = $imageUrl !== '' ? scrapeAbsoluteUrl($imageUrl, $firstUrl) : '';

    // ---- body, page by page ----
    // The pasted URL may itself be page 2+; pages are keyed by number and
    // put back in order at the end, so the article always starts at page 1.
    [$articleId, $firstNo] = scrapeSplitPageUrl($firstUrl);
    $pages    = [$firstNo => ['url' => $firstUrl, 'xp' => $xp, 'doc' => $doc]];
    $queue    = scrapeFindPageLinks($xp, $firstUrl, $articleId);
    $visited  = [preg_replace('/#.*$/', '', $firstUrl) => true];
    if ($firstNo > 1 && !isset($queue[1])) {
        $fp = parse_url($firstUrl);
        $queue[1] = ($fp['scheme'] ?? 'https') . '://' . ($fp['host'] ?? '')
            . preg_replace('#/(?:page/)?\d{1,2}/?$#i', '/', $fp['path'] ?? '/');
    }
    $warnings = [];
    $deadline = time() + 90;
    while (!empty($queue) && count($pages) < SCRAPE_MAX_PAGES) {
        ksort($queue);
        $n = array_key_first($queue);
        $pageUrl = $queue[$n];
        unset($queue[$n]);
        if (isset($visited[$pageUrl]) || isset($pages[$n])) {
            continue;
        }
        $visited[$pageUrl] = true;
        if (time() > $deadline) {
            $warnings[] = 'Stopped after ' . count($pages) . ' pages because the website is slow.';
            break;
        }
        $err = null;
        $res = scrapeHttpGet($pageUrl, SCRAPE_MAX_HTML_BYTES, $err);
        if ($res === null) {
            $warnings[] = 'Page ' . $n . ' could not be loaded: ' . $err;
            continue;
        }
        $pDoc = scrapeLoadDom($res['body'], $res['type']);
        $pXp  = new DOMXPath($pDoc);
        $pages[$n] = ['url' => $res['url'], 'xp' => $pXp, 'doc' => $pDoc];
        foreach (scrapeFindPageLinks($pXp, $res['url'], $articleId) as $k => $u) {
            if (!isset($pages[$k]) && !isset($visited[$u])) {
                $queue[$k] = $u;
            }
        }
    }
    ksort($pages);

    $parts = [];
    $seenParagraphs = [];
    foreach ($pages as $n => $p) {
        $node = scrapeFindContentNode($p['xp']);
        if ($node === null) {
            $warnings[] = 'No article text was found on page ' . $n . '.';
            continue;
        }
        if ($imageUrl === '' && empty($parts)) {
            foreach ($p['xp']->query('.//img', $node) as $img) {
                $src = $img instanceof DOMElement ? scrapeImgSrc($img, $p['url']) : '';
                $w = $img instanceof DOMElement ? (int) $img->getAttribute('width') : 0;
                if ($src !== '' && !preg_match('/\.svg(\?|$)/i', $src) && ($w === 0 || $w >= 300)) {
                    $imageUrl = $src;
                    break;
                }
            }
        }
        scrapeStripNoise($p['xp'], $node);
        $text = scrapeNodeToText($p['doc'], $node);
        $kept = [];
        foreach (preg_split('/\n{2,}/', $text) as $para) {
            // An ad label glued into a paragraph ("... she said. ADVERTISEMENT The next day ...").
            $para = trim(preg_replace('/\s*\bADVERTISEMENTS?\b\s*/u', ' ', $para));
            // Dates, bylines, ad labels, credits, widget labels on their own line.
            if (scrapeIsJunkParagraph($para, $authors)) {
                continue;
            }
            // The title repeated in the body (often at the top of every page),
            // and long paragraphs (disclaimers, intros) repeated on every page.
            if ($title !== '' && mb_strtolower($para) === mb_strtolower($title)) {
                continue;
            }
            $key = mb_strtolower(preg_replace('/\W+/u', '', $para));
            if (mb_strlen($para) >= 80 && isset($seenParagraphs[$key])) {
                continue;
            }
            $seenParagraphs[$key] = true;
            $kept[] = $para;
        }
        if (!empty($kept)) {
            $parts[] = implode("\n\n", $kept);
        }
    }
    $content = trim(implode("\n\n", $parts));

    if ($title === '' && $content === '') {
        return ['success' => false, 'message' => 'No article was found on that page.'];
    }
    if ($content === '') {
        $warnings[] = 'The title was found, but not the article text (the page may load it with JavaScript).';
    }

    $image = null;
    if ($imageUrl !== '') {
        $imgErr = null;
        $image = scrapeDownloadImage($imageUrl, $imgErr);
        if ($image === null) {
            $warnings[] = 'Featured image not imported: ' . $imgErr;
        }
    } else {
        $warnings[] = 'No featured image was found on the page.';
    }

    return [
        'success'    => true,
        'title'      => $title,
        'content'    => $content,
        'image'      => $image,
        'image_url'  => $imageUrl,
        'pages'      => count($pages),
        'source_url' => $firstUrl,
        'warnings'   => $warnings,
    ];
}
