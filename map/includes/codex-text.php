<?php
declare(strict_types=1);

function mapCodexRichHtml(string $source, ?callable $renderElement = null, ?callable $renderText = null): string
{
    if ($source === '') {
        return '';
    }
    $document = new DOMDocument();
    $document->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $source . '</body></html>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $body = $document->getElementsByTagName('body')->item(0);
    return $body === null ? '' : mapCodexRichChildren($body, $renderElement, $renderText);
}

function mapCodexRichChildren(DOMNode $parent, ?callable $renderElement = null, ?callable $renderText = null): string
{
    $allowed = ['p', 'div', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'sup', 'sub', 'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th',
        'pre', 'code', 'dl', 'dt', 'dd', 'br', 'hr'];
    $html = '';
    foreach ($parent->childNodes as $node) {
        if ($node instanceof DOMText) {
            $text = mapEscape($node->textContent);
            $html .= $renderText === null ? $text : $renderText($node, $text);
        } elseif ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'template', 'svg', 'math'], true)) {
                continue;
            }
            if ($tag === 'br' || $tag === 'hr') {
                $html .= '<' . $tag . '>';
                continue;
            }
            $content = mapCodexRichChildren($node, $renderElement, $renderText);
            $replacement = $renderElement === null ? null : $renderElement($node, $tag, $content);
            $html .= $replacement ?? (in_array($tag, $allowed, true) ? '<' . $tag . '>' . $content . '</' . $tag . '>' : $content);
        }
    }
    return $html;
}

function mapCodexTextAsHtml(string $text): string
{
    return $text === '' ? '' : '<p>' . nl2br(mapEscape($text), false) . '</p>';
}
