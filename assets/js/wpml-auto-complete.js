(function () {
    'use strict';

    var saved = false;

    /**
     * Remove HTML and whitespace for content checking.
     */
    function stripHtml(value) {
        return (value || '')
            .replace(/<[^>]*>/g, '')
            .replace(/\s+/g, '');
    }

    /**
     * Check whether a field contains actual content.
     */
    function hasContent(value) {
        return stripHtml(value).length > 0;
    }

    /**
     * Fire normal browser events.
     */
    function fireEvents(element) {
        if (!element) {
            return;
        }

        ['input', 'change', 'keyup'].forEach(function (eventName) {
            try {
                element.dispatchEvent(
                    new Event(eventName, {
                        bubbles: true,
                        cancelable: true
                    })
                );
            } catch (e) {
                // Older browser fallback
                var event = document.createEvent('Event');
                event.initEvent(eventName, true, true);
                element.dispatchEvent(event);
            }
        });
    }

    /**
     * Get field content from normal HTML fields.
     *
     * This handles:
     * - input[type=text]
     * - input[type=hidden]
     * - textarea
     * - other fields having [name="fields[key][data]"]
     */
    function getNormalFieldContent(key) {
        var selector =
            '[name="fields[' + key + '][data]"]';

        var elements = document.querySelectorAll(selector);

        for (var i = 0; i < elements.length; i++) {
            var element = elements[i];

            if (
                element.tagName === 'INPUT' ||
                element.tagName === 'TEXTAREA' ||
                element.tagName === 'SELECT'
            ) {
                var value = '';

                if (element.tagName === 'SELECT') {
                    value = element.value || '';
                } else {
                    value = element.value || '';
                }

                if (hasContent(value)) {
                    return {
                        value: value,
                        element: element
                    };
                }
            }
        }

        return {
            value: '',
            element: null
        };
    }

    /**
     * Get content from TinyMCE.
     */
    function getTinyMCEContent(key) {
        if (typeof window.tinymce === 'undefined') {
            return '';
        }

        var content = '';

        /*
         * Try common WPML/TinyMCE IDs.
         */
        var possibleIds = [
            'fields-' + key + '-data',
            'icl_tfield_' + key,
            key
        ];

        for (var i = 0; i < possibleIds.length; i++) {
            if (hasContent(content)) {
                break;
            }

            var editor = window.tinymce.get(possibleIds[i]);

            if (editor) {
                try {
                    content = editor.getContent();
                } catch (e) {}
            }
        }

        /*
         * Fallback: search all TinyMCE editors.
         */
        if (!hasContent(content)) {
            window.tinymce.editors.forEach(function (editor) {
                if (hasContent(content)) {
                    return;
                }

                if (!editor || !editor.id) {
                    return;
                }

                if (editor.id.indexOf(key) !== -1) {
                    try {
                        content = editor.getContent();
                    } catch (e) {}
                }
            });
        }

        return content;
    }

    /**
     * Synchronize TinyMCE content back to textarea.
     */
    function unlockTinyMCEFields() {

        if (typeof window.tinymce === 'undefined') {
            return;
        }

        window.tinymce.editors.forEach(function (editor) {

            if (!editor || !editor.initialized) {
                return;
            }

            try {
                var content = editor.getContent();

                if (!hasContent(content)) {
                    return;
                }

                /*
                 * Tell TinyMCE that the field changed.
                 */
                editor.fire('change');
                editor.fire('keyup');

                /*
                 * Sync content to underlying textarea.
                 */
                var textarea = document.getElementById(editor.id);

                if (textarea) {
                    textarea.value = content;
                    fireEvents(textarea);
                }

            } catch (e) {
                console.warn(
                    'WPML Porter: TinyMCE sync failed',
                    e
                );
            }
        });
    }

    /**
     * Find content for a WPML field.
     *
     * Handles:
     * - Title input
     * - Custom text inputs
     * - Textareas
     * - TinyMCE fields
     */
    function getFieldContent(key) {

        /*
         * 1. Normal input / textarea / select.
         */
        var normalField = getNormalFieldContent(key);

        if (hasContent(normalField.value)) {
            return normalField;
        }

        /*
         * 2. TinyMCE.
         */
        var tinyContent = getTinyMCEContent(key);

        if (hasContent(tinyContent)) {
            return {
                value: tinyContent,
                element: null
            };
        }

        return {
            value: '',
            element: null
        };
    }

    /**
     * Mark all populated WPML fields as complete.
     */
    function tickCompletedFields() {

        var ticked = 0;

        var checkboxes = document.querySelectorAll(
            '.js-field-translation-complete'
        );

        console.log(
            'WPML Porter: Found completion checkboxes:',
            checkboxes.length
        );

        checkboxes.forEach(function (checkbox) {

            /*
             * Already checked.
             */
            if (checkbox.checked) {
                return;
            }

            /*
             * WPML format:
             * fields[title][finished]
             * fields[body][finished]
             * fields[field-answer-0][finished]
             */
            var match = (checkbox.name || '').match(
                /^fields\[(.+?)\]\[finished\]$/
            );

            if (!match) {
                console.log(
                    'WPML Porter: Could not detect field key:',
                    checkbox.name
                );
                return;
            }

            var key = match[1];

            console.log(
                'WPML Porter: Checking field:',
                key
            );

            /*
             * Get field content.
             */
            var field = getFieldContent(key);

            /*
             * Nothing entered -> don't mark complete.
             */
            if (!hasContent(field.value)) {

                console.log(
                    'WPML Porter: Empty field, skipping:',
                    key
                );

                return;
            }

            console.log(
                'WPML Porter: Content found for:',
                key,
                field.value
            );

            /*
             * If this is a normal input/textarea,
             * make sure WPML receives the latest value.
             */
            if (field.element) {
                fireEvents(field.element);
            }

            /*
             * WPML may add disabled dynamically.
             * Remove it before clicking.
             */
            checkbox.disabled = false;
            checkbox.removeAttribute('disabled');

            /*
             * IMPORTANT:
             * Use the native click event instead of only
             * setting checkbox.checked = true.
             *
             * WPML listens for the click/change event.
             */
            try {
                checkbox.click();
            } catch (e) {
                /*
                 * Fallback.
                 */
                checkbox.checked = true;
                fireEvents(checkbox);
            }

            /*
             * Some WPML versions need an explicit change event.
             */
            if (!checkbox.checked) {
                checkbox.checked = true;
            }

            fireEvents(checkbox);

            /*
             * Make sure it really became checked.
             */
            if (checkbox.checked) {
                ticked++;

                console.log(
                    'WPML Porter: Marked complete:',
                    key
                );
            } else {
                console.warn(
                    'WPML Porter: Failed to check:',
                    key
                );
            }
        });

        console.log(
            'WPML Porter: Total fields checked:',
            ticked
        );

        return ticked;
    }

    /**
     * Save WPML Translation Editor.
     */
    function autoSave() {

        if (saved) {
            return;
        }

        saved = true;

        /*
         * Save TinyMCE content first.
         */
        if (
            typeof window.tinymce !== 'undefined' &&
            typeof window.tinymce.triggerSave === 'function'
        ) {
            window.tinymce.triggerSave();
        }

        /*
         * Try WPML save buttons in order.
         */
        var button =
            document.querySelector(
                'input[name="save"][type="submit"]'
            ) ||
            document.querySelector(
                'input[type="submit"].icl_tm_save'
            ) ||
            document.querySelector(
                '.icl-tm-footer input[type="submit"]'
            ) ||
            document.querySelector(
                'button[name="save"]'
            ) ||
            document.querySelector(
                'input[value*="Save"]'
            );

        if (button) {

            console.log(
                'WPML Porter: Saving translation...'
            );

            setTimeout(function () {
                button.click();
            }, 300);

        } else {

            console.warn(
                'WPML Porter: Save button not found.'
            );
        }
    }

    /**
     * Main process.
     */
    function run() {

        console.log(
            'WPML Porter: Starting auto-complete...'
        );

        /*
         * First synchronize TinyMCE.
         */
        unlockTinyMCEFields();

        /*
         * Give WPML a moment to finish rendering.
         */
        setTimeout(function () {

            var ticked = tickCompletedFields();

            /*
             * Save only if at least one field was checked.
             */
            if (ticked > 0) {

                console.log(
                    'WPML Porter: Fields checked:',
                    ticked
                );

                setTimeout(function () {
                    autoSave();
                }, 1200);

            } else {

                console.log(
                    'WPML Porter: No new fields were checked.'
                );
            }

        }, 500);
    }

    /**
     * Wait until WPML/TinyMCE is ready.
     */
    function waitForWPML(attempt) {

        attempt = attempt || 0;

        /*
         * Maximum ~9 seconds.
         */
        if (attempt > 30) {

            console.log(
                'WPML Porter: WPML ready timeout. Running anyway.'
            );

            run();
            return;
        }

        var tinyMCEReady =
            typeof window.tinymce !== 'undefined' &&
            window.tinymce.editors &&
            window.tinymce.editors.length > 0;

        /*
         * WPML completion checkboxes may be available
         * even when TinyMCE is not.
         */
        var wpmlReady =
            document.querySelectorAll(
                '.js-field-translation-complete'
            ).length > 0;

        if (tinyMCEReady || wpmlReady) {

            console.log(
                'WPML Porter: WPML editor detected.'
            );

            setTimeout(run, 500);

        } else {

            setTimeout(function () {
                waitForWPML(attempt + 1);
            }, 300);
        }
    }

    /**
     * Start after DOM is ready.
     */
    document.addEventListener(
        'DOMContentLoaded',
        function () {

            console.log(
                'WPML Porter: DOM ready.'
            );

            setTimeout(function () {
                waitForWPML(0);
            }, 800);
        }
    );

})();