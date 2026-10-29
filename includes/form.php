<?php
/**
 * Form field helpers so every form renders labels and errors the same way.
 */
declare(strict_types=1);

if (!defined('RJ_BOOT')) { http_response_code(403); exit; }

/** Error message under a field (with an id for aria-describedby). */
function field_error(array $errors, string $name): string
{
    if (empty($errors[$name])) {
        return '';
    }
    return '<p class="field__error" id="' . e($name) . '-error">' . e($errors[$name]) . '</p>';
}

/** Attributes that link an input to its error message. */
function field_aria(array $errors, string $name): string
{
    return empty($errors[$name])
        ? ''
        : ' aria-invalid="true" aria-describedby="' . e($name) . '-error"';
}

/** Summary box at the top of a form that failed validation. */
function form_errors_summary(array $errors): string
{
    if (!$errors) {
        return '';
    }
    $count = count($errors);
    $text = $count === 1 ? 'Fix 1 field below to continue.' : "Fix {$count} fields below to continue.";
    return '<p class="notice notice--error" role="alert">' . e($text) . '</p>';
}
