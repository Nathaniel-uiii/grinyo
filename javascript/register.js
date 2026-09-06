
//Age calculate and validation
function calculateAge() {
    var bdayInput = document.getElementById('bday');
    var ageInput = document.getElementById('age');
    if (!bdayInput || !ageInput) return;

    var bday = bdayInput.value;

    // If empty, clear age and show required error
    if (!bday || bday.trim() === '') {
        ageInput.value = '';
        showErrorMessage('age');
        // Allow user to continue filling other fields if birthdate is cleared
        toggleOtherInputsDisabledForAge(false);
        return;
    }

    var bdayDate = new Date(bday);
    // Guard invalid date
    if (isNaN(bdayDate.getTime())) {
        ageInput.value = '';
        showErrorMessage('age', 'Please enter a valid date of birth');
        // Disable other inputs while invalid date is present
        toggleOtherInputsDisabledForAge(true);
        return;
    }

    var today = new Date();
    // If birthdate is in the future, show a specific error and clamp age to 0
    if (bdayDate.getTime() > today.getTime()) {
        ageInput.value = '0';
        showErrorMessage('age', 'Birthday should not be in the future');
        toggleOtherInputsDisabledForAge(true);
        return;
    }

    var age = today.getFullYear() - bdayDate.getFullYear();
    var month = today.getMonth() - bdayDate.getMonth();
    if (month < 0 || (month === 0 && today.getDate() < bdayDate.getDate())) {
        age--;
    }

    // Clamp negative ages (future dates) to 0 for display
    if (age < 0) { age = 0; }

    ageInput.value = age;

    // Age validation - must be at least 18
    if (age < 18) {
        showErrorMessage('age', 'You must be at least 18 years old to register, please try again.');
        toggleOtherInputsDisabledForAge(true);
    } else {
        clearErrorMessage('age');
        toggleOtherInputsDisabledForAge(false);
    }
}

// Enable/disable other inputs when age is invalid
function toggleOtherInputsDisabledForAge(disabled) {
    // Do not disable any fields based on age; always ensure they remain enabled
    var all = document.querySelectorAll('input, select, textarea, button');
    for (var i = 0; i < all.length; i++) {
        var el = all[i];
        if (el) el.disabled = false;
    }
}

// Show error message
function showErrorMessage(fieldId, message) {
    var fieldInput = document.getElementById(fieldId);
    var existingError = document.getElementById(fieldId + '-error');
    
    if (existingError) {
        existingError.remove();
    }
    
    var errorDiv = document.createElement('div');
    errorDiv.id = fieldId + '-error';
    errorDiv.style.color = '#FF0000';
    errorDiv.style.fontSize = '.8rem';
    errorDiv.style.gap = '0px';
    errorDiv.textContent = message;
    
    var parentBox = fieldInput.parentNode; // usually .form-box or .password-wrapper
    // Place password errors just after the wrapper to keep them below the field in the same column
    if ((fieldId === 'pass' || fieldId === 'repass') && parentBox && parentBox.classList && parentBox.classList.contains('password-wrapper')) {
        var col = parentBox.parentNode; // column container
        if (col && col.insertBefore) {
            col.insertBefore(errorDiv, parentBox.nextSibling);
        } else {
            parentBox.insertBefore(errorDiv, fieldInput.nextSibling);
        }
    } else {
        // Default behavior for other fields: place error directly under the input (within its column)
        parentBox.insertBefore(errorDiv, fieldInput.nextSibling);
    }
    // Do not force focus here; allow user to navigate freely while errors are shown
}

// Clear error message
function clearErrorMessage(fieldId) {
    var existingError = document.getElementById(fieldId + '-error');
    if (existingError) {
        existingError.remove();
    }
    // Also remove the full-width password error row if present
    if (fieldId === 'pass') {
        var passRow = document.getElementById('pass-error');
        if (passRow && passRow.parentNode) {
            passRow.parentNode.removeChild(passRow);
        }
    }
}

// =========================
// Address field validations
// =========================

// Validate place fields (no spaces, start with capital letter, allowed A-Za-z0-9.-)
function validateNoSpacePlace(fieldId, label) {
    var input = document.getElementById(fieldId);
    if (!input) return true;
    var rawValue = (input.value || '');
    var value = rawValue.trim();

    // Disallow leading spaces (before any trimming) for all address fields
    if (/^\s/.test(rawValue)) {
        showErrorMessage(fieldId, 'Spaces should not be inputted first');
        return false;
    }

    // On input: if empty after trimming
    if (value === '') {
        // If the raw value still starts with a space (e.g., user typed only spaces),
        // keep the leading-space error instead of clearing it.
        if (/^\s+$/.test(rawValue)) {
            showErrorMessage(fieldId, 'Spaces should not be inputted first');
            return false;
        }
        // Otherwise, clear any existing error so it doesn't stick while typing
        clearErrorMessage(fieldId);
        return false;
    }

    // Barangay-specific: value must start with the word "Barangay"
    if (fieldId === 'brgy') {
        var brgyTrim = value; // already trimmed

        // Highest priority for Barangay: any double spaces anywhere
        if (/\s{2,}/.test(rawValue)) {
            showErrorMessage(fieldId, 'Double spaces not allowed');
            return false;
        }

        // Global for Barangay: consecutive hyphens are not allowed anywhere
        if (/--/.test(rawValue)) {
            showErrorMessage(fieldId, 'Consecutive hyphens "-" not allowed');
            return false;
        }

        // Case 1: starts with fully lowercase 'barangay' (first letter not uppercase)
        if (/^barangay\b/.test(brgyTrim)) {
            showErrorMessage(fieldId, 'First letter must be uppercase.');
            return false;
        }
        // Case 1b: correctly cased 'Barangay' but directly followed by a number/word without a space
        if (/^Barangay[0-9]/.test(brgyTrim)) {
            showErrorMessage(fieldId, 'Please put a space between "Barangay" and the number.');
            return false;
        }
        if (/^Barangay[A-Za-z]/.test(brgyTrim)) {
            showErrorMessage(fieldId, 'Please put a space between "Barangay" and the next word.');
            return false;
        }
        // Case 1c: 'Barangay-<...>' – any hyphen immediately after the word is not allowed
        // (e.g., 'Barangay-7', 'Barangay-Et'). Hyphens are only allowed later in the name.
        if (/^Barangay-\S+/.test(brgyTrim)) {
            showErrorMessage(fieldId, 'Please use space instead of a hyphen "-"');
            return false;
        }
        // Case 2: does not start with 'Barangay' in any form (only when first char is not special)
        // If it starts with a special character, let the generic 'No special characters allowed.' rule handle it.
        if (!/^[^A-Za-z0-9]/.test(rawValue) && !/^barangay\b/i.test(brgyTrim)) {
            showErrorMessage(fieldId, 'Please start with the word "Barangay"');
            return false;
        }
        // Case 3: starts with correctly cased 'Barangay' but nothing after it yet
        if (/^Barangay$/.test(brgyTrim)) {
            showErrorMessage(fieldId, 'Must be followed by a number or a word');
            return false;
        }
        // Case 3b: 'Barangay -...' where the first character after 'Barangay ' is a hyphen
        if (/^Barangay\s+-/.test(rawValue)) {
            showErrorMessage(fieldId, 'Should not start with a hyphen "-"');
            return false;
        }
        // Case 4: 'Barangay <number>' followed by extra letters/words (with or without space after the number)
        if (/^Barangay\s+\d+\s*[A-Za-z]/.test(rawValue.trim())) {
            showErrorMessage(fieldId, 'Do not add letters after the number.');
            return false;
        }
        // Case 5: 'Barangay <number>' followed only by extra spaces (use raw to see literal spaces)
        if (/^Barangay\s+\d+\s+$/.test(rawValue)) {
            showErrorMessage(fieldId, 'Do not add space after the barangay number.');
            return false;
        }
        // Otherwise (e.g., 'Barangay 1', 'Barangay Langit'), allow other rules to proceed
    }

    // Determine if spaces are allowed for this field early for ordering rules
    var allowSpaces = (fieldId === 'street' || fieldId === 'brgy' || fieldId === 'city' || fieldId === 'province' || fieldId === 'country');
    // Digits are allowed for street/brgy; not allowed for city/province/country
    var allowDigits = !(fieldId === 'city' || fieldId === 'province' || fieldId === 'country');

    // Purok detection/validation (street field only): must be exactly "Purok <number>".
    // Sequence:
    // 1) If it starts with some form of 'purok' but casing is not exactly 'Purok',
    //    show capitalization guidance first.
    // 2) If it starts with correctly cased 'Purok', enforce the strict Purok format.
    if (fieldId === 'street') {
        var rv = rawValue; // untrimmed for leading-space checks done earlier
        var rvTrim = rv.trim();
        // Step 1: wrong-cased 'purok' variants that start with uppercase P but have
        // incorrect casing in the rest of the word (e.g., PUROK1, PuroK1). When the
        // first letter itself is lowercase (e.g., purok1, pUROK1), let the global
        // "First letter must be uppercase." rule handle it instead.
        if (/^P/.test(rvTrim) && /^purok/i.test(rvTrim) && !/^Purok/.test(rvTrim)) {
            showErrorMessage(fieldId, 'Capitalize only the first letter of each word');
            return false;
        }
        // Step 2: only treat it as a Purok pattern when it starts with correctly cased 'Purok'
        if (/^Purok/.test(rvTrim)) {
            // Highest priority inside Purok mode: double spaces immediately after the word 'Purok'
            // (e.g., "Purok  1").
            if (/^Purok\s{2,}/.test(rv)) {
                showErrorMessage(fieldId, 'Double spaces not allowed');
                return false;
            }
            // First, if there are letters immediately after the Purok number, surface that
            // before space-related issues (e.g., "Purok 2A" or "Purok 2A  ").
            if (/^Purok\s+\d+[A-Za-z]/.test(rvTrim)) {
                showErrorMessage(fieldId, 'Letters are not allowed after the Purok number.');
                return false;
            }
            // Next: any spaces immediately after the Purok number (with or without extra text)
            if (/^Purok\s+\d+\s+/.test(rv)) {
                showErrorMessage(fieldId, 'There should be no spaces after the Purok number.');
                return false;
            }
            // Case 1: "Purok1" (no space between Purok and digits)
            var noSpaceMatch = /^Purok(\d+)/.exec(rvTrim);
            if (noSpaceMatch) {
                var numSample = noSpaceMatch[1];
                showErrorMessage(fieldId, 'The "Purok" and the number should be separated with space (ex: Purok ' + numSample + ').');
                return false;
            }
            // Must have something after 'Purok'
            if (/^Purok$/.test(rvTrim)) {
                showErrorMessage(fieldId, "Purok format must be 'Purok <number>' (ex. Purok 1).");
                return false;
            }
            // If the next character after 'Purok' is not a space (and not a digit caught above),
            // distinguish between letters and special characters.
            if (/^Purok\S/.test(rvTrim)) {
                // Case: letters immediately after the word 'Purok' (e.g., 'PurokA')
                if (/^Purok[A-Za-z]/.test(rvTrim)) {
                    showErrorMessage(fieldId, 'Letters are not allowed after the word "Purok".');
                    return false;
                }
                // Case: special character immediately after 'Purok' (e.g., 'Purok-' or 'Purok@')
                if (/^Purok[^A-Za-z0-9\s]/.test(rvTrim)) {
                    showErrorMessage(fieldId, 'Purok/Street must not contain any special character.');
                    return false;
                }
                // Fallback: generic guidance
                showErrorMessage(fieldId, "After 'Purok', add a space and a number (e.g. Purok 1).");
                return false;
            }
            // Now require digits only after the space, and no extra text (using trimmed value)
            if (!/^Purok\s+\d+$/.test(rvTrim)) {
                // First, if there are letters after a space with no number yet (e.g., "Purok A" or "Purok Ab@"),
                // prioritize the letters-after-Purok message even if special characters appear later.
                if (/^Purok\s+[A-Za-z]+/.test(rvTrim)) {
                    showErrorMessage(fieldId, 'Letters are not allowed after the word "Purok".');
                }
                // Otherwise, if special characters are present anywhere, surface the generic
                // special-character error for Purok/Street instead of a Purok-format error
                else if (/[^A-Za-z0-9\s]/.test(rv)) {
                    showErrorMessage(fieldId, 'Purok/Street must not contain any special character.');
                }
                // Case: space after Purok but no number yet
                else if (/^Purok\s+$/.test(rvTrim)) {
                    showErrorMessage(fieldId, "Provide a number after 'Purok' (e.g. Purok 1).");
                }
                return false;
            }
            // Valid Purok pattern
            clearErrorMessage(fieldId);
            return true;
        }
    }

        // Disallow uppercase letters beyond the first letter of each word
    // and three consecutive repeated letters
    // Applies to street, brgy, city, province, country
    if (fieldId === 'street' || fieldId === 'brgy' || fieldId === 'city' || fieldId === 'province' || fieldId === 'country') {
        // For Barangay and Purok/Street: allow hyphen as a separator but only single hyphen between tokens
        if ((fieldId === 'brgy' || fieldId === 'street')) {
            // Earliest-index prioritization for Purok/Barangay
            var earliestIdx = Infinity;
            var earliestMsg = '';
            var push = function (idx, msg) {
                if (idx !== -1 && idx < earliestIdx) { earliestIdx = idx; earliestMsg = msg; }
            };
            // For Purok/Street: any special character anywhere (including '-') is not allowed
            if (fieldId === 'street') {
                var specIdx = rawValue.search(/[^A-Za-z0-9\s]/);
                push(specIdx, 'Purok/Street must not contain any special character.');
            }
            // Leading cases (index 0 candidates)
            push(/^\s/.test(rawValue) ? 0 : -1, 'Spaces should not be inputted first');
            if (fieldId === 'brgy') {
                // For Barangay, a leading special character (including hyphen) should say:
                // 'Must not start with a special character.'
                push(/^[^A-Za-z0-9]/.test(rawValue) ? 0 : -1, 'Must not start with a special character.');
            }
            // Do not apply number-start rule for street or barangay
            push(/^[a-z]/.test(rawValue) ? 0 : -1, 'First letter must be uppercase.');

            // Double spaces: for street, city, province, country, let this compete
            // in earliest-index ordering (same idea as name fields)
            if (fieldId === 'street' || fieldId === 'city' || fieldId === 'province' || fieldId === 'country') {
                var dblIdx = rawValue.search(/\s{2,}/);
                push(dblIdx, 'Double spaces not allowed');
            }

            // Lowercase start of a subsequent word: prioritize this specific message
            (function(){
                var match = /\s([a-z])/.exec(rawValue);
                if (match) {
                    var idx = match.index + 1; // position of the lowercase letter itself
                    push(idx, 'First letter of each word must be capital.');
                }
            })();

            // Three consecutive same letters (case-insensitive)
            (function(){
                var lower = rawValue.toLowerCase();
                var idx = lower.search(/([a-z])\1\1/);
                push(idx, 'Three consecutive same letters not allowed');
            })();

            // Single-letter word tokens are not allowed (earliest occurrence)
            // For street, apply this only when there are no special characters, and if the
            // single letter after the first word is lowercase, surface a capitalization error.
            var hasSpecialForStreet = (fieldId === 'street') ? /[^A-Za-z0-9\s]/.test(rawValue) : false;
            if (fieldId !== 'street' || !hasSpecialForStreet) {
                (function(){
                    var reTok = /(^|[\s-])([A-Za-z])(?=($|[\s-]))/g, m;
                    while ((m = reTok.exec(rawValue)) !== null) {
                        // Position of the single-letter token itself
                        var pos = m.index + m[1].length;
                        var letter = m[2];
                        var before = rawValue.slice(0, pos);
                        var hasWordBefore = /\S/.test(before.replace(/\s+$/, ''));
                        if (fieldId === 'street' && hasWordBefore && /[a-z]/.test(letter)) {
                            push(pos, 'First letter of each word must be capital.');
                        } else {
                            push(pos, 'Each word must have at least 2 letters.');
                        }
                        break;
                    }
                })();
            }

            // All-caps word (do not override min-length for single-letter inputs)
            (function(){
                if (value.length < 2) return;
                var tokenRe = /[^\s-]+/g, m;
                while ((m = tokenRe.exec(rawValue)) !== null) {
                    var token = m[0];
                    if (token.length >= 2 && /^[A-Z]+$/.test(token)) {
                        var example = token.charAt(0) + token.slice(1).toLowerCase();
                        push(m.index, 'All capitals not allowed');
                        break;
                    }
                }
            })();

            // Capitalization per-word (earliest offending index): only first letter uppercase
            (function(){
                var re = /[^\s-]+/g, m, wordIdx = 0;
                // Internal caps should always use 'Capitalize only the first letter of each word'.
                // Lowercase-start of a later word is handled by the dedicated push above and
                // keeps its own message 'First letter of each word must be capital.'
                var capMsg = 'Capitalize only the first letter of each word';
                while ((m = re.exec(rawValue)) !== null) {
                    var tk = m[0];
                    if (!/^[A-Za-z]+$/.test(tk)) { wordIdx++; continue; }
                    // Subsequent words must start with a capital letter
                    if (wordIdx > 0 && /^[a-z]/.test(tk)) { push(m.index, 'First letter of each word must be capital.'); break; }
                    // No internal uppercase beyond the first character
                    var inside = tk.slice(1).match(/[A-Z]/);
                    if (inside) { push(m.index + 1 + inside.index, capMsg); break; }
                    wordIdx++;
                }
            })();


            // Interior digit inside a word (e.g., Ro3del) — show the whole offending token
            (function(){
                var tokenRe = /[^\s-]+/g, m;
                while ((m = tokenRe.exec(rawValue)) !== null) {
                    var token = m[0];
                    if (/[A-Za-z]\d+[A-Za-z]/.test(token)) {
                        push(m.index, 'Numbers are not allowed inside a word: "' + token + '".');
                        break;
                    }
                }
            })();

            // Letters immediately followed by digits in the same token (e.g., Mama1, PURO1)
            // For street: show spaces-only guidance. For non-street (e.g., brgy), keep the hyphen/space rule.
            if (fieldId === 'street') {
                (function(){
                    var tokenRe = /[^\s-]+/g, m;
                    while ((m = tokenRe.exec(rawValue)) !== null) {
                        var token = m[0];
                        var m2 = token.match(/^[A-Za-z]+\d+/);
                        if (m2) {
                            // Index of the first digit within this token
                            var firstDigitRel = token.search(/\d/);
                            var firstDigitAbs = m.index + firstDigitRel;
                            var prefix = token.slice(0, firstDigitRel);
                            // If the letters prefix has internal uppercase beyond the first
                            // character (e.g., MamA1, PurO1), surface capitalization first.
                            var inside = prefix.slice(1).match(/[A-Z]/);
                            if (inside) {
                                var upperAbs = m.index + 1 + inside.index;
                                var example = prefix.charAt(0).toUpperCase() + prefix.slice(1).toLowerCase();
                                var capMsgMixed = (fieldId === 'city' || fieldId === 'province' || fieldId === 'country')
                                    ? 'First letter of each word must be capital.'
                                    : 'Capitalize only the first letter of each word';
                                push(upperAbs, capMsgMixed);
                            } else {
                                push(firstDigitAbs, 'Words and numbers should be separated by a space.');
                            }
                            break;
                        }
                    }
                })();
            } else {
                (function(){
                    var tokenRe = /[^\s-]+/g, m;
                    while ((m = tokenRe.exec(rawValue)) !== null) {
                        var token = m[0];
                        var m2 = token.match(/^[A-Za-z]+\d+/);
                        if (m2) {
                            // Index of the first digit within this token
                            var firstDigitRel = token.search(/\d/);
                            var firstDigitAbs = m.index + firstDigitRel;
                            push(firstDigitAbs, 'Letters and numbers must be separated with a hyphen "-" or a space.');
                            break;
                        }
                    }
                })();
            }

            // Token beginning with digits then letters (e.g., 2AAdSe), but allow ordinals like 1st, 2nd, 3rd, 4th (street only)
            if (fieldId === 'street') {
                push(rawValue.search(/(?:^|[\s-])\d+(?!st\b|nd\b|rd\b|th\b)[A-Za-z]+/i), 'No letters after numbers unless using ordinals (ex. 1st, 2nd, 3rd).');
            }

            // Hyphen at end of a word (Apple- or Apple -) — only relevant for Barangay (street no longer allows special characters)
            if (fieldId === 'brgy') {
                for (var i = 0; i < rawValue.length; i++) {
                    if (rawValue[i] === '-') {
                        var prev = i > 0 ? rawValue[i-1] : '';
                        var next = i+1 < rawValue.length ? rawValue[i+1] : '';
                        if (/[A-Za-z]/.test(prev) && (next === '' || /\s/.test(next))) { push(i, 'Hyphen "-" cannot be at the end'); break; }
                    }
                }
            }

            if (earliestIdx !== Infinity) {
                showErrorMessage(fieldId, earliestMsg);
                return false;
            }
            // If no earlier issues, enforce minimum length next
            if (value.length < 2) {
                // Exception: for street, allow a single-digit value (e.g., "1")
                if (!(fieldId === 'street' && /^\d$/.test(value))) {
                    showErrorMessage(fieldId, 'Must be at least 2 characters');
                    return false;
                }
            }
            // Redundant checks removed; earliest-index block above covers hyphen spacing, double hyphen, and trailing hyphen.
            var tokens = rawValue.split(/[\s-]+/).filter(Boolean);
            for (var ti = 0; ti < tokens.length; ti++) {
                var t = tokens[ti];
                if (/[A-Za-z]\d+[A-Za-z]/.test(t)) {
                    showErrorMessage(fieldId, 'Numbers are not allowed inside a word: "' + t + '".');
                    return false;
                }
            }
            // Disallow letters after a number within the same token (e.g., "2AAdSe"),
            // but allow ordinals like 1st, 2nd, 3rd, 4th, etc. Also validate ordinal suffix.
            if (fieldId === 'street') {
                for (var tn = 0; tn < tokens.length; tn++) {
                    var t2 = tokens[tn];
                    if (/^\d+[A-Za-z]+$/.test(t2)) {
                        var om = t2.match(/^(\d+)([A-Za-z]+)$/);
                        var num = om ? parseInt(om[1], 10) : NaN;
                        var suf = om ? om[2].toLowerCase() : '';
                        var correctSuf = '';
                        if (!isNaN(num)) {
                            var lastTwo = num % 100;
                            var last = num % 10;
                            if (lastTwo >= 11 && lastTwo <= 13) {
                                correctSuf = 'th';
                            } else if (last === 1) {
                                correctSuf = 'st';
                            } else if (last === 2) {
                                correctSuf = 'nd';
                            } else if (last === 3) {
                                correctSuf = 'rd';
                            } else {
                                correctSuf = 'th';
                            }
                        }
                        if (om && (suf === 'st' || suf === 'nd' || suf === 'rd' || suf === 'th')) {
                            if (suf !== correctSuf) {
                                showErrorMessage(fieldId, 'Invalid ordinal, it must be: ' + num + correctSuf);
                                return false;
                            }
                            // valid ordinal; allow
                            continue;
                        }
                        showErrorMessage(fieldId, 'No letters after numbers unless using ordinals (ex. 1st, 2nd, 3rd).');
                        return false;
                    }
                }
            }
            // Guidance for mixing letters then numbers in one token does not apply to street anymore,
            // and is no longer needed for Barangay
            if (fieldId !== 'street' && fieldId !== 'brgy') {
                for (var tj = 0; tj < tokens.length; tj++) {
                    var tk = tokens[tj];
                    if (/^[A-Za-z]+\d+$/.test(tk)) {
                        showErrorMessage(fieldId, 'Letters and numbers must be separated with a hyphen "-" or a space.');
                        return false;
                    }
                }
            }
        }
        // three repeated letters handled in earliest-index block above for correct ordering
        // All-caps word check before capitalization guidance (street/brgy only here)
        if ((fieldId === 'street' || fieldId === 'brgy') && allowSpaces && value.length >= 2) {
            var tokensAllCaps = value.split(/[\s-]+/).filter(Boolean);
            for (var ai = 0; ai < tokensAllCaps.length; ai++) {
                var aw = tokensAllCaps[ai];
                if (/^[A-Z]+$/.test(aw)) {
                    var ex = aw.charAt(0) + aw.slice(1).toLowerCase();
                    showErrorMessage(fieldId, 'All capital letters not allowed. Example: ' + ex);
                    return false;
                }
            }
        }
        // Capitalization guidance: if any word has uppercase letters beyond the first
        // Show a dynamic example based on the first offending word (street/brgy only here).
        // For city/province/country we now rely solely on the earliest-index system above,
        // so this block must NOT run for them.
        if ((fieldId === 'street' || fieldId === 'brgy') && allowSpaces) { // only applies to fields where words with spaces are allowed
            var tokensCap = value.split(/[\s-]+/).filter(Boolean);
            for (var ci = 0; ci < tokensCap.length; ci++) {
                var w = tokensCap[ci];
                if (!/^[A-Za-z]+$/.test(w)) continue;
                if (/[A-Z]/.test(w.slice(1))) {
                    var example = w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
                    var capMsgField = (fieldId === 'city' || fieldId === 'province' || fieldId === 'country')
                        ? 'First letter of each word must be capital.'
                        : 'Capitalize only the first letter of each word';
                    showErrorMessage(fieldId, capMsgField);
                    return false;
                }
            }
        }
    }

    // If first character is lowercase, surface that error first (even if length < 2)
    if ((fieldId === 'street' || fieldId === 'brgy' || fieldId === 'city' || fieldId === 'province' || fieldId === 'country') && value.length >= 1) {
        if (/^[a-z]/.test(value)) {
            showErrorMessage(fieldId, 'First letter must be uppercase.');
            return false;
        }
        if (fieldId !== 'street' && fieldId !== 'brgy' && /^\d/.test(value)) {
            showErrorMessage(fieldId, 'Must not start with a number.');
            return false;
        }
    }

    // Early: if first character is a special character, surface that before min-length
    if ((fieldId === 'street' || fieldId === 'brgy' || fieldId === 'city' || fieldId === 'province' || fieldId === 'country') &&
        /^[^A-Za-z0-9]/.test(rawValue)) {
        showErrorMessage(fieldId, 'Must not start with a special character.');
        return false;
    }

    // Minimum length rule for City, Province, and Country (Purok/Barangay handled earlier)
    if ((fieldId === 'city' || fieldId === 'province' || fieldId === 'country') && value.length < 2) {
        showErrorMessage(fieldId, 'Must be at least 2 characters');
        return false;
    }

    // Maximum length rule (50 characters) for address fields
    if ((fieldId === 'street' || fieldId === 'brgy' || fieldId === 'city' || fieldId === 'province' || fieldId === 'country') && value.length > 50) {
        showErrorMessage(fieldId, 'Maximum of 50 characters only');
        return false;
    }

    // Allow spaces for these fields when configured; otherwise keep no-space rule
    // Additionally, disallow double spaces for allowed-space fields
    // allowSpaces already set above
    // For fields that disallow spaces entirely (street, brgy), detect any space immediately using rawValue
    if (allowSpaces && /\s{2,}/.test(rawValue)) {
        showErrorMessage(fieldId, 'Double spaces not allowed');
        return false;
    }

    

    // For city/province/country, numbers are not allowed; for street/brgy, digits are allowed
    // Apply earliest-index prioritization for city/province/country
    if (!allowDigits && (fieldId === 'city' || fieldId === 'province' || fieldId === 'country')) {
        var cIdx = Infinity, cMsg = '';
        var pick = function(idx, msg){ if (idx !== -1 && idx < cIdx) { cIdx = idx; cMsg = msg; } };
        // Min-length handled earlier; now check earliest offenders
        var leadMsg = '';
        if (/^\s/.test(rawValue)) leadMsg = 'Spaces should not be inputted first';
        else if (/^[^A-Za-z0-9]/.test(rawValue)) leadMsg = 'Must not start with a special character.';
        else if (/^[a-z]/.test(rawValue)) leadMsg = 'First letter must be uppercase.';
        else if (/^\d/.test(rawValue)) leadMsg = 'Must not start with a number.';
        if (leadMsg) { showErrorMessage(fieldId, leadMsg); return false; }
        // If no leading issues, compute earliest among remaining
        // Any digit anywhere
        pick(value.search(/\d/), 'Numbers are not allowed');
        // Triple letters (case-insensitive)
        pick(value.toLowerCase().search(/([a-z])\1\1/), 'Three consecutive same letters not allowed');
        // Capitalization per-word: enforce first letter uppercase only, earliest offending token
        (function(){
            var re = /[^\s]+/g, m, wordIdx = 0;
            while ((m = re.exec(value)) !== null) {
                var tk = m[0];
                if (!/^[A-Za-z]+$/.test(tk)) { wordIdx++; continue; }
                var corrected = tk.charAt(0).toUpperCase() + tk.slice(1).toLowerCase();
                // Subsequent words must start with a capital letter
                if (wordIdx > 0 && /^[a-z]/.test(tk)) {
                    var capMsgCityLike = (fieldId === 'city' || fieldId === 'province' || fieldId === 'country')
                        ? 'First letter of each word must be capital.'
                        : 'Capitalize only the first letter of each word';
                    pick(m.index, capMsgCityLike);
                    break;
                }
                // No internal uppercase beyond the first character
                var inside = tk.slice(1).match(/[A-Z]/);
                if (inside) {
                    var capMsgCityLike2 = (fieldId === 'city' || fieldId === 'province' || fieldId === 'country')
                        ? 'First letter of each word must be capital.'
                        : 'Capitalize only the first letter of each word';
                    pick(m.index + 1 + inside.index, capMsgCityLike2);
                    break;
                }
                wordIdx++;
            }
        })();
        // Single-letter word tokens are not allowed for City/Province/Country
        (function(){
            var reTok = /(^|[\s])([A-Za-z])(?=($|[\s]))/g, m;
            while ((m = reTok.exec(value)) !== null) {
                var pos = m.index + m[1].length;
                pick(pos, 'Each word must have at least 2 letters.');
                break;
            }
        })();
        // All-caps token
        (function(){
            var toks = value.split(/\s+/).filter(Boolean);
            var offset = 0;
            for (var i = 0; i < value.length; i++) {
                // compute positions via regex instead of manual offset
            }
        })();
        // Lightweight index for all-caps using regex with exec to get position
        (function(){
            var re = /[^\s]+/g, m;
            while ((m = re.exec(value)) !== null) {
                var tk = m[0];
                if (/^[A-Z]+$/.test(tk)) {
                    var ex = tk.charAt(0) + tk.slice(1).toLowerCase();
                    pick(m.index, 'All capitals not allowed');
                    break;
                }
            }
        })();
        if (cIdx !== Infinity) {
            showErrorMessage(fieldId, cMsg);
            return false;
        }
    }
    // Additional rule for street: if it starts with a number or ordinal, it must have a word/road type after it
    if (fieldId === 'street') {
        var tokensStreet = value.split(/\s+/).filter(Boolean);
        var firstTok = tokensStreet[0] || '';
        var isPureNumber = /^\d+$/.test(firstTok);
        var ordMatch = firstTok.match(/^(\d+)(st|nd|rd|th)$/i);
        var isOrdinal = !!ordMatch;
        var ordNum = isOrdinal ? parseInt(ordMatch[1], 10) : NaN;
        var ordSuf = isOrdinal ? ordMatch[2].toLowerCase() : '';
        var ordCorrect = '';
        if (isOrdinal && !isNaN(ordNum)) {
            var oLastTwo = ordNum % 100;
            var oLast = ordNum % 10;
            if (oLastTwo >= 11 && oLastTwo <= 13) {
                ordCorrect = 'th';
            } else if (oLast === 1) {
                ordCorrect = 'st';
            } else if (oLast === 2) {
                ordCorrect = 'nd';
            } else if (oLast === 3) {
                ordCorrect = 'rd';
            } else {
                ordCorrect = 'th';
            }
        }

        if (tokensStreet.length === 1) {
            if (isPureNumber) {
                showErrorMessage(fieldId, 'Street number must have a word or road type after it.');
                return false;
            }
            if (isOrdinal) {
                if (ordSuf !== ordCorrect) {
                    showErrorMessage(fieldId, 'Invalid ordinal, it must be: ' + ordNum + ordCorrect);
                } else {
                    showErrorMessage(fieldId, 'Ordinal number must have a word or road type after it.');
                }
                return false;
            }
        }
        if (tokensStreet.length >= 2) {
            var secondTok = tokensStreet[1] || '';
            if (isPureNumber && !/[A-Za-z]/.test(secondTok)) {
                showErrorMessage(fieldId, 'Street number must have a word or road type after it.');
                return false;
            }
            if (isOrdinal && !/[A-Za-z]/.test(secondTok)) {
                if (ordSuf !== ordCorrect) {
                    showErrorMessage(fieldId, 'Invalid ordinal, it must be: ' + ordNum + ordCorrect);
                } else {
                    showErrorMessage(fieldId, 'No letters after numbers unless using ordinals (ex. 1st, 2nd, 3rd).');
                }
                return false;
            }
        }
    }

    var re;
    if (allowSpaces) {
        if (allowDigits) {
            // For Purok/Street and Barangay: allow only hyphen as special char (no period)
            // Street is allowed to start with a number (e.g., "23" or "23 Purok")
            if (fieldId === 'street') {
                re = /^[A-Za-z0-9][A-Za-z0-9 -]*$/;
            } else if (fieldId === 'brgy') {
                re = /^[A-Z][A-Za-z0-9 -]*$/;
            } else {
                re = /^[A-Z][A-Za-z0-9 .-]*$/;
            }
        } else {
            // For City/Province/Country: no special characters allowed (letters and spaces only)
            re = /^[A-Z][A-Za-z ]*$/;
        }
    } else {
        re = /^[A-Z][A-Za-z0-9.-]*$/;
    }
    if (!re.test(value)) {
        var msg = '';
        if (fieldId !== 'street' && /^\d/.test(value)) msg = 'Must not start with a number.';
        else if (/^[a-z]/.test(value)) msg = 'First letter must be uppercase.';
        else if (!allowSpaces && /\s/.test(rawValue)) msg = 'No spaces allowed. Use hyphen (-) instead.';
        else if (fieldId !== 'brgy' && /^[^A-Za-z0-9]/.test(rawValue)) msg = 'Special characters are not allowed.';
        else if (fieldId === 'street') msg = 'Purok/Street must not contain any special character.';
        else if (fieldId === 'brgy') msg = 'Only hyphen "-" is the allowed special character.';
        else msg = 'No special characters allowed.';
        showErrorMessage(fieldId, msg);
        return false;
    }
    clearErrorMessage(fieldId);
    return true;
}

// ZIP code: exactly 4 digits
function validateZip4(fieldId) {
    var input = document.getElementById(fieldId);
    if (!input) return true;
    var value = (input.value || '').trim();
    if (value === '') { clearErrorMessage(fieldId); return false; }
    if (!/^\d{4}$/.test(value)) {
        showErrorMessage(fieldId, 'Zip Code must be 4 digits');
        return false;
    }
    clearErrorMessage(fieldId);
    return true;
}


// Email validation: basic pattern with '@' and domain
function validateEmail(fieldId) {
    var input = document.getElementById(fieldId);
    if (!input) return true;
    var raw = (input.value || '');
    // Leading space should trigger a specific error
    if (/^\s/.test(raw)) {
        showErrorMessage(fieldId, 'Email should not contain spaces');
        return false;
    }
    // Determine precedence among: invalid local special, space, and a second '@' by earliest index in RAW
    var firstAtRaw = raw.indexOf('@');
    var secondAtRaw = (firstAtRaw !== -1) ? raw.indexOf('@', firstAtRaw + 1) : -1;
    var firstSpaceRaw = raw.search(/\s/);
    // First invalid local special BEFORE first '@' (ignore spaces here)
    var firstInvalidLocalRaw = -1;
    var scanEnd = (firstAtRaw === -1 ? raw.length : firstAtRaw);
    for (var ri = 0; ri < scanEnd; ri++) {
        var rc = raw.charAt(ri);
        if (!/[A-Za-z0-9.]/.test(rc) && !/\s/.test(rc)) { firstInvalidLocalRaw = ri; break; }
    }
    if (secondAtRaw !== -1 || firstSpaceRaw !== -1 || firstInvalidLocalRaw !== -1) {
        var minPos = Infinity;
        var kind0 = '';
        if (firstInvalidLocalRaw !== -1 && firstInvalidLocalRaw < minPos) { minPos = firstInvalidLocalRaw; kind0 = 'badLocal'; }
        if (firstSpaceRaw !== -1 && firstSpaceRaw < minPos) { minPos = firstSpaceRaw; kind0 = 'space'; }
        if (secondAtRaw !== -1 && secondAtRaw < minPos) { minPos = secondAtRaw; kind0 = 'secondAt'; }
        if (kind0 === 'badLocal') {
            showErrorMessage(fieldId, 'Dot "." is the only allowed special character in the local part');
            return false;
        } else if (kind0 === 'space') {
            showErrorMessage(fieldId, 'Email should not contain spaces');
            return false;
        } else if (kind0 === 'secondAt') {
            showErrorMessage(fieldId, "Email must contain only one '@'.");
            return false;
        }
    }
    var value = raw.trim();
    // Do not show required here; keep it for submit/blur required helper
    if (value === '') {
        // If raw is truly empty, clear any email error; otherwise, keep existing
        // space-related errors (e.g., 'Email should not contain spaces').
        if (raw === '') { clearErrorMessage(fieldId); }
        return false;
    }

    // Local part must not start with a special character (check first char before '@')
    var firstChar = value.charAt(0);
    if (firstChar && !/[A-Za-z0-9]/.test(firstChar)) {
        showErrorMessage(fieldId, 'Local part must not start with a special character');
        return false;
    }
    // 2) Must contain '@' — but if the current input is a valid local-part candidate < 3 chars, show that error first
    var atIndex = value.indexOf('@');
    if (atIndex === -1) {
        var localCandidate = value;
        if (localCandidate !== '' && /^[A-Za-z0-9.]+$/.test(localCandidate) && localCandidate.length < 3) {
            showErrorMessage(fieldId, 'Local part must be at least 3 characters');
            return false;
        }
        showErrorMessage(fieldId, "Email must contain '@'");
        return false;
    }
    // Global whitespace rule (after finding an '@'): any whitespace anywhere is invalid
    if (/\s/.test(raw)) {
        showErrorMessage(fieldId, 'Email should not contain spaces');
        return false;
    }

    // Validate allowed characters in local part before any further @-related checks
    var localCandidateAfterAt = value.slice(0, atIndex);
    if (/[^A-Za-z0-9.]/.test(localCandidateAfterAt)) {
        showErrorMessage(fieldId, 'Dot "." is the only allowed special character in the local part');
        return false;
    }

    // If a second '@' exists anywhere, it takes absolute precedence over all other errors
    var secondAtPos = value.indexOf('@', atIndex + 1);
    if (secondAtPos !== -1) {
        showErrorMessage(fieldId, "Email must contain only one '@'.");
        return false;
    }

    // Earliest-index error prioritization across the whole string (with single '@')
    // Consider: consecutive specials, invalid local/domain special chars
    var consecMatch = /[^A-Za-z0-9]{2,}/.exec(value);
    var consecPos = consecMatch ? consecMatch.index : -1;
    // First invalid in local (global index)
    var firstBadLocalPos = -1;
    for (var li = 0; li < atIndex; li++) {
        var ch = value.charAt(li);
        if (/[^A-Za-z0-9.]/.test(ch)) { firstBadLocalPos = li; break; }
    }
    // First invalid in domain (global index)
    var firstBadDomainPos = -1;
    var domStart = atIndex + 1;
    for (var di = 0; di < value.length - domStart; di++) {
        var chd = value.charAt(domStart + di);
        if (/[^A-Za-z0-9.]/.test(chd)) { firstBadDomainPos = domStart + di; break; }
    }

    var minIdx = -1; var kind = '';
    function consider(idx, k){ if (idx >= 0 && (minIdx === -1 || idx < minIdx)) { minIdx = idx; kind = k; } }
    consider(consecPos, 'consec');
    consider(firstBadLocalPos, 'badLocal');
    consider(firstBadDomainPos, 'badDomain');
    if (minIdx >= 0) {
        if (kind === 'consec') {
            showErrorMessage(fieldId, 'Email must not contain consecutive special characters');
        } else if (kind === 'badLocal') {
            showErrorMessage(fieldId, 'Dot "." is the only allowed special character in the local part');
        } else if (kind === 'badDomain') {
            showErrorMessage(fieldId, 'Dot "." is the only allowed special character in the domain part');
        }
        return false;
    }
    // Redundant fallbacks removed; earliest-index scan already handles second '@' and spaces
    // 3) Local and domain parts must be non-empty
    var local = value.slice(0, atIndex);
    var domain = value.slice(atIndex + 1);
    if (local.length === 0) {
        showErrorMessage(fieldId, "Email must have text before '@'");
        return false;
    }
    // Local part minimum length: 3 characters (must come before checking domain emptiness)
    if (local.length < 3) {
        showErrorMessage(fieldId, 'Local part must be at least 3 characters');
        return false;
    }
    if (domain.length === 0) {
        showErrorMessage(fieldId, "Email must have text after '@'");
        return false;
    }
    // Redundant local/domain special-char checks removed; earliest-index scan covers them already
    // Local part must not end with any special character (e.g., ., -, _, etc.)
    if (!/[A-Za-z0-9]$/.test(local)) {
        showErrorMessage(fieldId, 'Local part must not end with a special character');
        return false;
    }
    // 4) Domain must have a dot and not end with a dot
    var lastDotInDomain = domain.lastIndexOf('.');
    if (lastDotInDomain === -1) {
        showErrorMessage(fieldId, "Email domain must contain '.'");
        return false;
    }
    // Do not add a special-case message for ending with '.'; general end-of-email rule covers it
    // 5) No consecutive special characters (e.g., __, ++, .., --)
    if (/[^A-Za-z0-9]{2,}/.test(value)) {
        showErrorMessage(fieldId, 'Email must not contain consecutive special characters');
        return false;
    }
    // 6) Domain labels must be non-empty
    var labels = domain.split('.');
    for (var i = 0; i < labels.length; i++) {
        if (!labels[i]) {
            showErrorMessage(fieldId, 'Email domain should not be empty');
            return false;
        }
        // No additional hyphen boundary rule per request
    }

    // 7) Email must end with a letter (not a number or special character)
    var lastChar = value[value.length - 1];
    if (!/[A-Za-z]/.test(lastChar)) {
        showErrorMessage(fieldId, /\d/.test(lastChar) ? 'Email should not end with a number' : 'Email should not end with a special character');
        return false;
    }

    clearErrorMessage(fieldId);
    return true;
}

// Check if email already exists in database (AJAX)
function checkEmailExists(emailValue) {
    var emailInput = document.getElementById('email');
    if (!emailInput) return false;

    // Clear error if field is empty
    if (emailValue === '' || emailValue.trim() === '') {
        clearErrorMessage('email');
        return false;
    }

    // Require valid format before checking server (use our validator)
    if (!validateEmail('email')) {
        return false;
    }

    emailInput.setAttribute('data-validating', 'true');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '../server/check_email.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4) {
            emailInput.removeAttribute('data-validating');
            if (xhr.status == 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.exists === true) {
                        showErrorMessage('email', 'Email already exists. Please use a different email.');
                        return false;
                    } else {
                        // Keep any format error cleared if server says ok
                        clearErrorMessage('email');
                        return true;
                    }
                } catch (e) {
                    console.error('Error parsing email check response:', e);
                    return false;
                }
            }
        }
    };

    xhr.send('email=' + encodeURIComponent(emailValue));
    return false; // Assume invalid until we get a response
}


// Check for three consecutive repeated letters (case-insensitive)
function hasThreeRepeatedLetters(str) {
    var lowerStr = str.toLowerCase();
    for (var i = 0; i <= lowerStr.length - 3; i++) {
        if (lowerStr[i] === lowerStr[i + 1] && lowerStr[i] === lowerStr[i + 2]) {
            return true;
        }
    }
    return false;
}

// Check for incorrectly capitalized letters in name (only letters after first)
function findIncorrectCapitalization(nameValue) {
    var atStart = true; // start of first token
    for (var i = 0; i < nameValue.length; i++) {
        var ch = nameValue[i];
        if (ch === ' ' || ch === '-') { atStart = true; continue; }
        if (ch >= 'A' && ch <= 'Z') {
            if (!atStart) { return { position: i + 1, word: nameValue }; }
            atStart = false;
        } else if (ch >= 'a' && ch <= 'z') {
            atStart = false;
        } else {
            // other characters do not affect token start
        }
    }
    return null;
}

// getPositionText removed; using a generic capitalization guidance message instead

// Name validation
function validateName(fieldId) {
    var nameInput = document.getElementById(fieldId);
    var nameValue = nameInput.value;
    var specialCharPattern = /[^a-zA-Z\s]/;
    var doubleSpacePattern = /\s{2,}/;
    var allCapsPattern = /^[A-Z\s]+$/;
    var properCasePattern = /^[A-Z][a-z]*(?:\s[A-Z][a-z]*)*$/;
    
    if (/^\s/.test(nameValue)) {
        showErrorMessage(fieldId, 'Spaces should not be inputted first');
        return false;
    } else if (nameValue === '') {
        clearErrorMessage(fieldId);
        return true;
    }

    if (/^[a-z]/.test(nameValue)) {
        showErrorMessage(fieldId, 'First letter must be capital.');
        return false;
    }

    // Compute indices for all possible content errors and pick the earliest
    var idxNum = nameValue.search(/\d/);
    var idxSpec = nameValue.search(specialCharPattern);
    var idxDbl = nameValue.search(doubleSpacePattern);
    // Incorrect capitalization inside a word (uppercase not at token start)
    var idxCap = (function(){
        var atStart = true;
        for (var i = 0; i < nameValue.length; i++) {
            var ch = nameValue[i];
            if (ch === ' ' || ch === '-') { atStart = true; continue; }
            if (ch >= 'A' && ch <= 'Z') {
                if (!atStart) return i; // first uppercase in the middle of a token
                atStart = false;
            } else if (ch >= 'a' && ch <= 'z') {
                atStart = false;
            }
        }
        return -1;
    })();
    // First all-caps word occurrence (prefer this over generic inside-word capitalization)
    var idxAllCaps = (function(){
        var m, re = /[^\s-]+/g; // word tokens separated by space or hyphen
        while ((m = re.exec(nameValue)) !== null) {
            var tok = m[0];
            if (!/[A-Za-z]/.test(tok)) continue;
            if (tok.length > 1 && tok === tok.toUpperCase()) {
                return m.index + 1; // surface at the second character of the all-caps word
            }
        }
        return -1;
    })();
    // Three consecutive same letters (case-insensitive)
    var idxTriple = nameValue.toLowerCase().search(/([a-z])\1\1/);
    // First single-letter word occurrence (for names like 'Rodel E')
    var idxSingle = (function(){
        var m = /(^|\s)([A-Za-z])(?=($|\s))/.exec(nameValue);
        if (!m) return -1;
        return m.index + m[1].length; // position of the single-letter token
    })();
    // First subsequent word starting with lowercase (space + [a-z])
    var idxLowerAfter = (function(){
        var m = /\s([a-z])/.exec(nameValue);
        return m ? (m.index + 1) : -1; // position of the lowercase letter itself
    })();

    var minIdx = -1;
    var kind = '';
    if (idxNum >= 0) { minIdx = idxNum; kind = 'num'; }
    if (idxSpec >= 0 && (minIdx === -1 || idxSpec < minIdx)) { minIdx = idxSpec; kind = 'spec'; }
    // Prefer triple-letter when tied at the same index with capitalization
    if (idxTriple >= 0 && (minIdx === -1 || idxTriple < minIdx)) { minIdx = idxTriple; kind = 'triple'; }
    // Prefer ALL-CAPS over generic inside-word capitalization
    if (idxAllCaps >= 0 && (minIdx === -1 || idxAllCaps < minIdx)) { minIdx = idxAllCaps; kind = 'allcaps'; }
    if (idxCap >= 0 && (minIdx === -1 || idxCap < minIdx)) { minIdx = idxCap; kind = 'cap'; }
    if (idxDbl >= 0 && (minIdx === -1 || idxDbl < minIdx)) { minIdx = idxDbl; kind = 'dbl'; }
    if (idxSingle >= 0 && (minIdx === -1 || idxSingle < minIdx)) { minIdx = idxSingle; kind = 'single'; }
    if (idxLowerAfter >= 0 && (minIdx === -1 || idxLowerAfter < minIdx)) { minIdx = idxLowerAfter; kind = 'lower'; }
    if (minIdx >= 0) {
        if (kind === 'num') {
            showErrorMessage(fieldId, 'Numbers are not allowed');
        } else if (kind === 'spec') {
            showErrorMessage(fieldId, 'No special characters allowed');
        } else if (kind === 'allcaps') {
            // Show all-caps guidance with corrected example for the first offending token
            var tokensAC = nameValue.trim().split(/\s+/);
            var offendingProperAC = '';
            for (var ac = 0; ac < tokensAC.length; ac++) {
                var t = tokensAC[ac];
                if (!t) continue;
                var hasLetterAC = /[A-Za-z]/.test(t);
                if (hasLetterAC && t.length > 1 && t === t.toUpperCase()) {
                    var cleanedAC = t.replace(/[^A-Za-z]/g, '');
                    offendingProperAC = cleanedAC
                        ? (cleanedAC.charAt(0).toUpperCase() + cleanedAC.slice(1).toLowerCase())
                        : '';
                    break;
                }
            }
            showErrorMessage(fieldId, 'All capitals not allowed');
        } else if (kind === 'single') {
            showErrorMessage(fieldId, 'Each word must have at least 2 letters.');
        } else if (kind === 'dbl') {
            showErrorMessage(fieldId, 'Double spaces not allowed');
        } else if (kind === 'lower') {
            showErrorMessage(fieldId, 'First letter of each word must be capital.');
        } else if (kind === 'cap') {
            // Mirror the capitalization guidance with example
            var tokens = nameValue.trim().split(/\s+/);
            var offendingProper = '';
            for (var ti = 0; ti < tokens.length; ti++) {
                var tok = tokens[ti];
                if (!tok) continue;
                var cleaned = tok.replace(/[^A-Za-z]/g, '');
                var properTok = cleaned
                    ? (cleaned.charAt(0).toUpperCase() + cleaned.slice(1).toLowerCase())
                    : '';
                if (tok !== properTok && properTok) { offendingProper = properTok; break; }
            }
            var exampleWord = offendingProper || (function(){
                var first = tokens[0] || '';
                var c = first.replace(/[^A-Za-z]/g, '');
                return c ? (c.charAt(0).toUpperCase() + c.slice(1).toLowerCase()) : '';
            })();
            showErrorMessage(fieldId, 'Capitalize only the first letter of each word');
        } else if (kind === 'triple') {
            showErrorMessage(fieldId, 'Three consecutive same letters not allowed');
        } else {
            showErrorMessage(fieldId, 'Double spaces not allowed');
        }
        return false;
    }

    var trimmed = nameValue.trim();
    if (trimmed.length < 2) {
        // Only show min-length if current content is otherwise a valid single letter
        if (/^[A-Za-z]$/.test(trimmed)) {
            showErrorMessage(fieldId, 'Must be at least 2 characters');
            return false;
        }
    }

    // (moved) three-consecutive-letters check will run after capitalization guidance

    // If any individual word is ALL CAPS, surface that first with an example of the corrected word
    if (nameValue.trim() !== '') {
        var words = nameValue.trim().split(/\s+/);
        for (var wi = 0; wi < words.length; wi++) {
            var w = words[wi];
            if (!w) continue;
            var hasLetter = /[A-Za-z]/.test(w);
            var isAllCaps = hasLetter && (w === w.toUpperCase());
            if (isAllCaps && w.length > 1) {
                var corrected = w.charAt(0).toUpperCase() + w.slice(1).toLowerCase();
                showErrorMessage(fieldId, 'All capitals not allowed');
                return false;
            }
        }
    }

    if (properCasePattern.test(nameValue.trim()) == false && nameValue.trim() != '') {
        if (!(nameValue[0] >= 'a' && nameValue[0] <= 'z')) {
            var incorrectCap = findIncorrectCapitalization(nameValue);
            if (incorrectCap) {
                var tokens = nameValue.trim().split(/\s+/);
                var offending = '';
                for (var ti = 0; ti < tokens.length; ti++) {
                    var tok = tokens[ti];
                    if (!tok) continue;
                    var properTok = tok.charAt(0).toUpperCase() + tok.slice(1).toLowerCase();
                    if (tok !== properTok) { offending = properTok; break; }
                }
                var exampleWord = offending || (tokens[0] ? (tokens[0].charAt(0).toUpperCase() + tokens[0].slice(1).toLowerCase()) : '');
                showErrorMessage(fieldId, 'Capitalize only the first letter of each word');
            }
        }
        return false;
    }

    // Three consecutive letters is already handled in earliest-index checks above

    // Length limit shown after earlier per-index errors are addressed
    if (nameValue.trim().length > 30) {
        showErrorMessage(fieldId, 'Maximum of 30 characters only');
        return false;
    }

    else {
        clearErrorMessage(fieldId);
    }
    
    return true;
}

// Extension name validation (allows Roman numerals)
function validateExtensionName(fieldId) {
    var extInput = document.getElementById(fieldId);
    var extValue = extInput.value;
    var romanNumeralPattern = /^[IVXLCDM]+$/;
    var startsWithLowercase = /^[a-z]/;
    
    // Max length check is evaluated last, after all other errors are resolved

    if (extValue == '') {
        clearErrorMessage(fieldId);
        return true;
    }

    // Specific guidance for Jr/Sr casing when exactly two letters are entered
    if (/^[A-Za-z]{2}$/.test(extValue)) {
        if (/^[jJ][rR]$/.test(extValue)) {
            if (extValue === 'jr' || extValue === 'jR') {
                showErrorMessage(fieldId, "First letter must be uppercase for 'Jr'");
                return false;
            } else if (extValue === 'JR') {
                showErrorMessage(fieldId, "'R' must be lower case for 'Jr'");
                return false;
            }
            // 'Jr' will be accepted by the allowed check below
        } else if (/^[sS][rR]$/.test(extValue)) {
            if (extValue === 'sr' || extValue === 'sR') {
                showErrorMessage(fieldId, "First letter must be uppercase for 'Sr'");
                return false;
            } else if (extValue === 'SR') {
                showErrorMessage(fieldId, "'R' must be lowercase for 'Sr'");
                return false;
            }
            // 'Sr' will be accepted by the allowed check below
        }
    }

    // If the value begins with a Jr/Sr token (any case), ensure correct casing first,
    // even if additional characters follow (so casing message doesn't get overridden)
    var m = /^([jJ][rR])/.exec(extValue) || /^([sS][rR])/.exec(extValue);
    if (m) {
        var tok = m[1];
        if (tok === 'jr' || tok === 'jR') { showErrorMessage(fieldId, "First letter must be uppercase for 'Jr'"); return false; }
        if (tok === 'JR') { showErrorMessage(fieldId, "'R' must be lower case for 'Jr'"); return false; }
        if (tok === 'sr' || tok === 'sR') { showErrorMessage(fieldId, "First letter must be uppercase for 'Sr'"); return false; }
        if (tok === 'SR') { showErrorMessage(fieldId, "'R' must be lowercase for 'Sr'"); return false; }
    }

    // If the value begins with Roman numeral letters (any length), and any letter in that
    // leading Roman segment is lowercase, surface capitalization error before other checks.
    var rm = /^([ivxlcdmIVXLCDM]+)/.exec(extValue);
    if (rm) {
        var seg = rm[1];
        if (seg !== seg.toUpperCase()) {
            showErrorMessage(fieldId, 'Roman Numerals should be capitalized');
            return false;
        }
    }

    if (romanNumeralPattern.test(extValue) == true) {
        if (extValue.length > 5) {
            showErrorMessage(fieldId, 'Maximum of 5 characters only');
            return false;
        }
        // Valid Roman numeral - no error
        clearErrorMessage(fieldId);
        return true;
    } else if (/^(Jr|Sr)$/.test(extValue)) {
        // Allow exact Jr or Sr
        clearErrorMessage(fieldId);
        return true;
    } else {
        // If it begins with a valid prefix, validate the remainder specifically
        var jrSrPrefix = /^(Jr|Sr)/.exec(extValue);
        var romanPrefix = /^([IVXLCDM]+)/.exec(extValue);

        if (jrSrPrefix) {
            var restJ = extValue.slice(jrSrPrefix[0].length);
            if (restJ.length > 0) {
                // Earliest-index selection among space, digit, special, or letter
                var pSpaceJ = restJ.search(/\s/);
                var pNumJ = restJ.search(/\d/);
                var pSpecJ = restJ.search(/[^A-Za-z0-9\s]/);
                var pLetJ = restJ.search(/[A-Za-z]/);
                var minJ = -1, kindJ = '';
                if (pSpaceJ >= 0) { minJ = pSpaceJ; kindJ = 'space'; }
                if (pNumJ >= 0 && (minJ === -1 || pNumJ < minJ)) { minJ = pNumJ; kindJ = 'num'; }
                if (pSpecJ >= 0 && (minJ === -1 || pSpecJ < minJ)) { minJ = pSpecJ; kindJ = 'spec'; }
                if (pLetJ >= 0 && (minJ === -1 || pLetJ < minJ)) { minJ = pLetJ; kindJ = 'letter'; }
                if (minJ >= 0) {
                    if (kindJ === 'letter') {
                        showErrorMessage(fieldId, 'Only Jr, Sr, or Roman numerals (I, II, III, IV, …) are allowed');
                    } else if (kindJ === 'space') {
                        showErrorMessage(fieldId, 'Spaces are not allowed');
                    } else if (kindJ === 'num') {
                        showErrorMessage(fieldId, 'Numbers are not allowed');
                    } else {
                        showErrorMessage(fieldId, 'Special characters are not allowed');
                    }
                    return false;
                }
            }
        } else if (romanPrefix) {
            var restR = extValue.slice(romanPrefix[1].length);
            if (restR.length > 0) {
                // Earliest-index selection among space, digit, special, or letter
                var pSpaceR = restR.search(/\s/);
                var pNumR = restR.search(/\d/);
                var pSpecR = restR.search(/[^A-Za-z0-9\s]/);
                var pLetR = restR.search(/[A-Za-z]/);
                var minR = -1, kindR = '';
                if (pSpaceR >= 0) { minR = pSpaceR; kindR = 'space'; }
                if (pNumR >= 0 && (minR === -1 || pNumR < minR)) { minR = pNumR; kindR = 'num'; }
                if (pSpecR >= 0 && (minR === -1 || pSpecR < minR)) { minR = pSpecR; kindR = 'spec'; }
                if (pLetR >= 0 && (minR === -1 || pLetR < minR)) { minR = pLetR; kindR = 'letter'; }
                if (minR >= 0) {
                    if (kindR === 'letter') {
                        showErrorMessage(fieldId, 'Only Jr, Sr, or Roman numerals (I, II, III, IV, …) are allowed');
                    } else if (kindR === 'space') {
                        showErrorMessage(fieldId, 'Spaces are not allowed');
                    } else if (kindR === 'num') {
                        showErrorMessage(fieldId, 'Numbers are not allowed');
                    } else {
                        showErrorMessage(fieldId, 'Special characters are not allowed');
                    }
                    return false;
                }
            }
        } else {
            // If it starts with a letter but is not Jr/Sr nor Roman numerals,
            // prefer the generic rule message over trailing issues
            if (/^[A-Za-z]/.test(extValue)) {
                showErrorMessage(fieldId, 'Only Jr, Sr, or Roman numerals (I, II, III, IV, …) are allowed');
                return false;
            }

            // Granular invalid checks across the whole string (no valid prefix)
            var idxSpace = extValue.search(/\s/);
            var idxNum = extValue.search(/\d/);
            var idxSpec = extValue.search(/[^A-Za-z0-9\s]/);

            var minIdx = -1; var kind = '';
            if (idxSpace >= 0) { minIdx = idxSpace; kind = 'space'; }
            if (idxNum >= 0 && (minIdx === -1 || idxNum < minIdx)) { minIdx = idxNum; kind = 'num'; }
            if (idxSpec >= 0 && (minIdx === -1 || idxSpec < minIdx)) { minIdx = idxSpec; kind = 'spec'; }

            if (minIdx >= 0) {
                if (kind === 'space') {
                    showErrorMessage(fieldId, 'Spaces are not allowed');
                } else if (kind === 'num') {
                    showErrorMessage(fieldId, 'Numbers are not allowed');
                } else {
                    showErrorMessage(fieldId, 'Special characters are not allowed');
                }
                return false;
            }
        }
    }

    if (startsWithLowercase.test(extValue) == true) {
        showErrorMessage(fieldId, 'First letter should not be small');
        return false;
    } else if (!/^(Jr|Sr)$/.test(extValue)) {
        // Reject any other alphabetic extension
        showErrorMessage(fieldId, 'Only Jr, Sr, or Roman numerals (I, II, III, IV, …) are allowed');
        return false;
    } else {
        // Final guard: enforce max length only after all other issues are resolved
        if (extValue.length > 5) {
            showErrorMessage(fieldId, 'Maximum of 5 characters only');
            return false;
        }
        clearErrorMessage(fieldId);
    }
    
    return true;
}

// ID format validation
function validateIdFormat() {
    var idInput = document.getElementById('id');
    var idValue = idInput.value;
    var idPattern = /^\d{4}-\d{4}$/;
    var letterPattern = /[a-zA-Z]/;
    var invalidSpecialPattern = /[^0-9-]/; // any special char other than dash
    
    if (idValue == '') {
        clearErrorMessage('id');
        return true;
    } else if (letterPattern.test(idValue) == true) {
        showErrorMessage('id', 'ID does not contain letters');
        return false;
    } else if (invalidSpecialPattern.test(idValue) == true) {
        showErrorMessage('id', 'Hyphen "-" is the only allowed special character.');
        return false;
    } else if (idPattern.test(idValue) == false) {
        showErrorMessage('id', 'Please enter ID in format: xxxx-xxxx');
        return false;
    } else {
        clearErrorMessage('id');
    }
    
    return true;
}

// Check if ID already exists in database
function checkIdExists(idValue) {
    if (idValue == '' || /^\d{4}-\d{4}$/.test(idValue) == false) {
        return;
    }
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '../server/check_id.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4 && xhr.status == 200) {
            var response = JSON.parse(xhr.responseText);
            
            if (response.exists == true) {
                showErrorMessage('id', 'ID already exists. Please use a different ID.');
            } else {
                clearErrorMessage('id');
            }
        }
    };
    
    xhr.send('id=' + encodeURIComponent(idValue));
}

// Check if username already exists in database
function checkUsernameExists(usernameValue) {
    var usernameInput = document.getElementById('user');
    
    // Clear error if field is empty
    if (usernameValue === '' || usernameValue.trim() === '') {
        clearErrorMessage('user');
        return false; // Return false to indicate no error
    }
    
    // Show loading state
    usernameInput.setAttribute('data-validating', 'true');
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '../server/check_username.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4) {
            usernameInput.removeAttribute('data-validating');
            
            if (xhr.status == 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    
                    if (response.exists === true) {
                        showErrorMessage('user', 'Username already exists. Please choose a different username.');
                        return false;
                    } else {
                        clearErrorMessage('user');
                        return true;
                    }
                } catch (e) {
                    console.error('Error parsing username check response:', e);
                    return false;
                }
            }
        }
    };
    
    xhr.send('username=' + encodeURIComponent(usernameValue));
    return false; // Assume invalid until we get a response
}



// ID validation logic
function handleIdValidation() {
    if (validateIdFormat() == true) {
        var idInput = document.getElementById('id');
        var idValue = idInput.value;
        if (idValue != '' && /^\d{4}-\d{4}$/.test(idValue) == true) {
            checkIdExists(idValue);
        }
    }
}

// Trim all name fields before form submission
function trimNameFields() {
    var nameFields = ['fname', 'mname', 'lname', 'ename'];
    for (var i = 0; i < nameFields.length; i++) {
        var field = document.getElementById(nameFields[i]);
        if (field) {
            field.value = field.value.trim();
        }
    }
}

// Password show/hide toggle (applies to both #pass and #repass)
var eyeiconRegister = document.getElementById("eyeicon-register");
var passwordRegister = document.getElementById("pass");
var repassRegister = document.getElementById("repass");

if (eyeiconRegister) {
    eyeiconRegister.onclick = function(){
        // Determine desired state: show if either is currently hidden
        var shouldShow = (passwordRegister && passwordRegister.type === "password") || (repassRegister && repassRegister.type === "password");
        if (shouldShow) {
            if (passwordRegister) passwordRegister.type = "text";
            if (repassRegister) repassRegister.type = "text";
            eyeiconRegister.classList.remove("fa-eye-slash");
            eyeiconRegister.classList.add("fa-eye");
        } else {
            if (passwordRegister) passwordRegister.type = "password";
            if (repassRegister) repassRegister.type = "password";
            eyeiconRegister.classList.remove("fa-eye");
            eyeiconRegister.classList.add("fa-eye-slash");
        }
    }
}

// Run when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Allow navigating between fields even if there are errors
    var ALLOW_NAV_WITH_ERRORS = true;
    // ID validation
    var idInput = document.getElementById('id');
    if (idInput) {
        var timeoutId;
        idInput.addEventListener('input', function() {
            clearTimeout(timeoutId);
            timeoutId = setTimeout(handleIdValidation, 500);
        });
        idInput.addEventListener('blur', handleIdValidation);
    }
    
    // Birthdate / Age validation
    var bdayInput = document.getElementById('bday');
    var ageInput = document.getElementById('age');
    if (bdayInput) {
        bdayInput.addEventListener('input', function() {
            calculateAge();
        });
        bdayInput.addEventListener('blur', function() {
            calculateAge();
        });
        // Run once on load to set initial state
        calculateAge();
    }
    

    // Removed global focus guard to allow free navigation even when errors exist
    
    // Username validation (format: lowercase letters >=5 + '_' + digits)
    var usernameInput = document.getElementById('user');
    if (usernameInput) {
      var usernameTimeoutId;

      // Removed username Tab trap to allow navigation while errors are present

      // Real-time validation with debounce
      usernameInput.addEventListener('input', function() {
        clearTimeout(usernameTimeoutId);
        var rawU = this.value;
        if (rawU === '') { clearErrorMessage('user'); return; }

        // Leading character–based priority, then interior rules
        var firstChar = rawU.charAt(0);
        // 1) Leading space
        if (/\s/.test(firstChar)) { showErrorMessage('user', 'Spaces are not allowed in username'); return; }
        // 2) Leading underscore (disallowed as first character)
        if (firstChar === '_') { showErrorMessage('user', 'Must not start with a special character.'); return; }
        // 3) Leading special (not letter/digit/underscore)
        if (/[^A-Za-z0-9_]/.test(firstChar)) { showErrorMessage('user', 'Must not start with a special character.'); return; }
        // 4) Leading digit
        if (/[0-9]/.test(firstChar)) { showErrorMessage('user', 'Must not start with a number.'); return; }
        // 5) Leading capital
        if (/[A-Z]/.test(firstChar)) { showErrorMessage('user', 'Capital letters are not allowed'); return; }
        // 5) Early underscore-suffix rule: any non-digit after '_' wins over interior capital
        var idxUndEarly = rawU.indexOf('_');
        if (idxUndEarly !== -1) {
          var suffixEarly = rawU.slice(idxUndEarly + 1);
          if (suffixEarly && /[^0-9]/.test(suffixEarly)) {
            showErrorMessage('user', 'Only numbers are allowed after the underscore.');
            return;
          }
        }
        // Interior rules (only reached if first char is not space/special/digit/capital)
        if (/\s/.test(rawU)) { showErrorMessage('user', 'Spaces are not allowed in username'); return; }
        if (/[A-Z]/.test(rawU)) { showErrorMessage('user', 'Capital letters are not allowed'); return; }

        // Global: only one underscore allowed (highest priority once typing)
        if ((rawU.match(/_/g) || []).length > 1) { showErrorMessage('user', 'Only one underscore "_" is allowed'); return; }

        // 2) Staged format checks (prefix first when underscore exists)
        var idxUnd = rawU.indexOf('_');
        if (idxUnd === -1) {
          // No underscore yet: allow only lowercase letters/digits/single underscore (global disallows apply here)
          if (/[A-Z]/.test(rawU)) { showErrorMessage('user', 'Capital letters are not allowed'); return; }
          if (/[^a-z0-9_]/.test(rawU)) { showErrorMessage('user', 'Underscore "_" is the only allowed special character'); return; }

          // Early-stage guidance: once user starts with a valid lowercase letter but
          // has fewer than 5 letters and no underscore yet, show format hint.
          if (/^[a-z]/.test(rawU) && rawU.length < 5) {
            showErrorMessage('user', 'Username format must be ex. xxxxx_12345');
            return;
          }

          var letters = (rawU.match(/^[a-z]+/) || [''])[0].length;
          if (letters > 10) { showErrorMessage('user', 'Maximum of 10 letters only'); return; }
          if (letters < 5) { showErrorMessage('user', 'Letters must be at least 5 characters long.'); return; }
          // 5+ letters but underscore not yet typed
          if (/^[a-z]{5,}$/.test(rawU)) { showErrorMessage('user', 'An underscore "_" is required after the first word.'); return; }
          // If digits appear before underscore
          if (/^[a-z]{5,}[0-9]+$/.test(rawU)) { showErrorMessage('user', 'Underscore is missing. Format must be: xxxxx_1234.'); return; }
        } else {
          var prefix = rawU.slice(0, idxUnd);
          var suffix = rawU.slice(idxUnd + 1);
          // Prefix prioritization: uppercase before special-character error
          if (/[A-Z]/.test(prefix)) { showErrorMessage('user', 'Capital letters are not allowed'); return; }
          if (/[^a-z0-9]/.test(prefix)) { showErrorMessage('user', 'Underscore "_" is the only allowed special character'); return; }
          if (/\d/.test(prefix)) { showErrorMessage('user', 'Underscore "_" must be between letters and numbers (e.g. xxxxx_1234).'); return; }

          // Prefix length bounds: 5 to 10 letters
          if (!/^[a-z]+$/.test(prefix) || prefix.length < 5) { showErrorMessage('user', 'Letters must be at least 5 characters long.'); return; }
          if (prefix.length > 10) { showErrorMessage('user', 'Maximum of 10 letters only'); return; }

          // Suffix-first validation: any non-digit after '_' should show the digits-only message
          if (suffix === '') { showErrorMessage('user', 'Numbers must come after the underscore.'); return; }
          if (!/^\d+$/.test(suffix)) { showErrorMessage('user', 'Only numbers are allowed after the underscore.'); return; }

          // Suffix length bound: max 6 digits
          if (suffix.length > 6) { showErrorMessage('user', 'Maximum of 6 numbers only'); return; }

          // After suffix is valid digits, apply remaining global disallows
          if (/\s/.test(rawU)) { showErrorMessage('user', 'Spaces are not allowed in username'); return; }
          if (/[^a-z0-9_]/.test(rawU)) { showErrorMessage('user', 'Underscore "_" is the only allowed special character'); return; }
        }

        // Valid format -> clear error and check availability
        var valid = /^[a-z]{5,}_[0-9]+$/.test(rawU);
        if (!valid) return; // keep showing stage message
        clearErrorMessage('user');

        usernameTimeoutId = setTimeout(function() {
            checkUsernameExists(usernameInput.value);
        }, 500);
      });

      // Validate on blur
      usernameInput.addEventListener('blur', function() {
        clearTimeout(usernameTimeoutId);
        var rawU = this.value;
        // If field is truly empty, clear error
        if (rawU === '') { clearErrorMessage('user'); return; }
        // Leading character–based priority, then interior rules (same as input handler)
        var firstCharB = rawU.charAt(0);
        if (/\s/.test(firstCharB)) { showErrorMessage('user', 'Spaces are not allowed in username'); return; }
        // Leading underscore (disallowed as first character)
        if (firstCharB === '_') { showErrorMessage('user', 'Must not start with a special character.'); return; }
        // Other leading special characters
        if (/[^A-Za-z0-9_]/.test(firstCharB)) { showErrorMessage('user', 'Must not start with a special character.'); return; }
        if (/[0-9]/.test(firstCharB)) { showErrorMessage('user', 'Must not start with a number.'); return; }
        if (/[A-Z]/.test(firstCharB)) { showErrorMessage('user', 'Capital letters are not allowed'); return; }
        var idxUndEarlyB = rawU.indexOf('_');
        if (idxUndEarlyB !== -1) {
          var suffixEarlyB = rawU.slice(idxUndEarlyB + 1);
          if (suffixEarlyB && /[^0-9]/.test(suffixEarlyB)) {
            showErrorMessage('user', 'Only numbers are allowed after the underscore.');
            return;
          }
        }
        if (/\s/.test(rawU)) { showErrorMessage('user', 'Spaces are not allowed in username'); return; }
        if (/[A-Z]/.test(rawU)) { showErrorMessage('user', 'Capital letters are not allowed'); return; }
        // Repeat staged validation on blur; prefer the explicit missing-underscore message if no underscore
        // Global: only one underscore allowed
        if ((rawU.match(/_/g) || []).length > 1) { showErrorMessage('user', 'Only one underscore "_" is allowed'); return; }
        var idxUnd = rawU.indexOf('_');
        if (idxUnd === -1) {
          if (/[^a-z0-9_]/.test(rawU)) { showErrorMessage('user', 'Underscore "_" is the only allowed special character'); return; }
          var letters = (rawU.match(/^[a-z]+/) || [''])[0].length;
          showErrorMessage('user', 'Underscore is missing. Format must be: xxxxx_1234.'); return;
        } else {
          var prefix = rawU.slice(0, idxUnd);
          var suffix = rawU.slice(idxUnd + 1);
          if (/[A-Z]/.test(prefix)) { showErrorMessage('user', 'Capital letters are not allowed'); return; }
          if (/[^a-z0-9]/.test(prefix)) { showErrorMessage('user', 'Underscore "_" is the only allowed special character'); return; }
          if (/\d/.test(prefix)) { showErrorMessage('user', 'Underscore "_" must be between letters and numbers (e.g. xxxxx_1234).'); return; }
          if (suffix === '') { showErrorMessage('user', 'Numbers must come after the underscore.'); return; }
          if (!/^\d+$/.test(suffix)) { showErrorMessage('user', 'Only numbers are allowed after the underscore.'); return; }
          if (/[^a-z0-9_]/.test(rawU)) { showErrorMessage('user', 'Underscore "_" is the only allowed special character'); return; }
        }
        // If fully valid on blur, check availability
        if (/^[a-z]{5,}_[0-9]+$/.test(rawU)) { clearErrorMessage('user'); checkUsernameExists(this.value); }
      });

      // On focus: show format hint only when field is empty
      usernameInput.addEventListener('focus', function() {
        var rawU = this.value || '';
        if (rawU === '') {
          clearErrorMessage('user');
        }
      });

        // Personal Information: wire up name field validations (first, middle, last)
        var nameFields = ['fname', 'mname', 'lname'];
        for (var nf = 0; nf < nameFields.length; nf++) {
            (function(fid){
                var el = document.getElementById(fid);
                if (!el) return;
                try { el.setAttribute('autocomplete', 'off'); } catch(_) {}
                var handler = function(){ validateName(fid); };
                el.addEventListener('input', handler);
                el.addEventListener('keyup', handler);
                el.addEventListener('change', function(){
                    var raw = el.value || '';
                    var trimmed = raw.trim();
                    if (trimmed !== '' && /\s$/.test(raw)) {
                        showErrorMessage(fid, "Don't leave unnecessary space at the end.");
                        return;
                    }
                    handler();
                });
                el.addEventListener('blur', function(){
                    var raw = el.value || '';
                    var trimmed = raw.trim();
                    // Apply trailing space rule only when leaving the field
                    if (trimmed !== '' && /\s$/.test(raw)) {
                        showErrorMessage(fid, "Don't leave unnecessary space at the end.");
                        return;
                    }
                    handler();
                });
            })(nameFields[nf]);
        }

        // Address Information: apply trailing-space validation on blur/change
        var addressFields = ['street', 'brgy', 'city', 'province', 'country'];
        for (var af = 0; af < addressFields.length; af++) {
            (function(fid2){
                var el2 = document.getElementById(fid2);
                if (!el2) return;
                var checkTrail = function(){
                    var raw2 = el2.value || '';
                    var trimmed2 = raw2.trim();
                    // For street with Purok pattern, let the Purok-specific rule handle trailing spaces
                    if (fid2 === 'street' && /^Purok\s+\d+\s+$/.test(raw2)) {
                        return;
                    }
                    var errEl = document.getElementById(fid2 + '-error');
                    if (trimmed2 !== '' && /\s$/.test(raw2)) {
                        // Only show trailing-space error if there is no other error,
                        // or if the existing error is already the trailing-space message.
                        if (errEl && !/unnecessary space at the end/i.test(errEl.textContent || '')) {
                            return;
                        }
                        showErrorMessage(fid2, "Don't leave unnecessary space at the end.");
                        return;
                    }
                    // Clear only this specific message if present; otherwise leave other errors
                    if (errEl && /unnecessary space at the end/i.test(errEl.textContent || '')) {
                        clearErrorMessage(fid2);
                    }
                };
                el2.addEventListener('change', checkTrail);
                el2.addEventListener('blur', checkTrail);
            })(addressFields[af]);
        }

        // Prevent form submission if fields are empty (step-by-step) and preserve existing error logic
        var form = document.querySelector('form');
        if (form) {
            form.setAttribute('novalidate', 'novalidate');
            // Helper: clear all generic required-only errors immediately
            function clearAllRequiredErrors() {
                var errs = form.querySelectorAll('[id$="-error"]');
                for (var i = 0; i < errs.length; i++) {
                    var el = errs[i];
                    if (/this field is required/i.test(el.textContent || '')) {
                        el.parentNode && el.parentNode.removeChild(el);
                    }
                }
            }
            form.addEventListener('input', function(){
                // On any typing, remove all generic required messages to declutter the form.
                clearAllRequiredErrors();
            }, true);
            form.addEventListener('change', function(){
                clearAllRequiredErrors();
            }, true);
            form.addEventListener('focusin', function(){
                if (window.__requiredStepFocusOverride) return;
                clearAllRequiredErrors();
            }, true);

            // Ensure trailing-space error shows when leaving name fields (robust at form level)
            form.addEventListener('focusout', function(ev){
                var t = ev.target;
                if (!t) return;
                var tid = t.id || '';
                if (tid === 'fname' || tid === 'mname' || tid === 'lname' ||
                    tid === 'street' || tid === 'brgy' || tid === 'city' || tid === 'province' || tid === 'country') {
                    var raw = t.value || '';
                    var trimmed = raw.trim();
                    // If value starts with a space, keep the leading-space error as highest priority
                    if (/^\s/.test(raw)) {
                        showErrorMessage(tid, 'Spaces should not be inputted first');
                        return;
                    }
                    // For street with Purok pattern, let the Purok-specific rule handle trailing spaces
                    if (tid === 'street' && /^Purok\s+\d+\s+$/.test(raw)) {
                        return;
                    }
                    if (trimmed !== '' && /\s$/.test(raw)) {
                        var errEl2 = document.getElementById(tid + '-error');
                        // Only show trailing-space error if there is no other error,
                        // or if the existing error is already the trailing-space message.
                        if (errEl2 && !/unnecessary space at the end/i.test(errEl2.textContent || '')) {
                            return;
                        }
                        showErrorMessage(tid, "Don't leave unnecessary space at the end.");
                    }
                }
            }, true);

            form.addEventListener('submit', function(e) {
                // Clear previous required-only errors to avoid stale messages elsewhere
                var prevRequired = form.querySelectorAll('[id$="-error"]');
                for (var ci2 = 0; ci2 < prevRequired.length; ci2++) {
                    var errEl = prevRequired[ci2];
                    // Remove any error attached to currently empty fields to allow ordered progression
                    var fidFromErr = errEl.id.replace('-error','');
                    var fld = document.getElementById(fidFromErr);
                    var emptyNow = false;
                    if (fld) {
                        var vv = (fld.value || '').trim();
                        if (fld.tagName === 'SELECT') vv = fld.value;
                        emptyNow = (vv === '');
                    }
                    if (errEl && (emptyNow || /required/i.test(errEl.textContent || ''))) {
                        errEl.parentNode && errEl.parentNode.removeChild(errEl);
                    }
                }
                // Enforce a strict progression order regardless of DOM layout
                // Show required message on ALL empty fields; focus first empty
                var requiredOrder = ['id','fname','lname','bday','age','sex','email','street','brgy','city','province','country','zipcode','user','pass','repass'];
                var firstEmpty = null;
                var anyEmpty = false;
                for (var i = 0; i < requiredOrder.length; i++) {
                    var fid2 = requiredOrder[i];
                    var f = document.getElementById(fid2);
                    if (!f) continue;
                    var v2 = (f.value || '').trim();
                    if (f.tagName === 'SELECT') v2 = f.value;
                    if (v2 === '') {
                        anyEmpty = true;
                        if (!firstEmpty) firstEmpty = f;
                        showErrorMessage(fid2, 'This field is required');
                    }
                }
                if (anyEmpty) {
                    e.preventDefault();
                    return;
                }

                var userErr = document.getElementById('user-error');
                var ageErr = document.getElementById('age-error');
                var emailErr = document.getElementById('email-error');
                var passErr = document.getElementById('pass-error');
                if (userErr || ageErr || emailErr) {
                    e.preventDefault();
                }
                var pVal = (passEl && passEl.value) || '';
                var rVal = (repassEl && repassEl.value) || '';
                if (pVal !== rVal) {
                    e.preventDefault();
                    showErrorMessage('repass', '');
                    return;
                }
                if (hasSpace(pVal) || hasSpace(rVal) || passErr) {
                    e.preventDefault();
                }
            });
        }
    }
    
    // Address fields validation (live + on blur)
    var placeFields = [
        { id: 'street', label: 'Purok/Street' },
        { id: 'brgy',   label: 'Barangay' },
        { id: 'city',   label: 'City/Municipality' },
        { id: 'province', label: 'Province' },
        { id: 'country',  label: 'Country' }
    ];
    for (var p = 0; p < placeFields.length; p++) {
        (function(cfg){
            var el = document.getElementById(cfg.id);
            if (!el) return;
            el.addEventListener('input', function(){
                // live validate but do not force required while typing
                validateNoSpacePlace(cfg.id, cfg.label);
            });
            el.addEventListener('blur', function(){
                // On blur: if empty, clear any error (no required message here)
                var v = (this.value || '').trim();
                if (v === '') {
                    clearErrorMessage(cfg.id);
                    return;
                }
                // If not empty, validate pattern
                validateNoSpacePlace(cfg.id, cfg.label);
            });
        })(placeFields[p]);
    }

    // Zip code validation
    var zipEl = document.getElementById('zipcode');
    if (zipEl) {
        zipEl.addEventListener('input', function(){
            // keep only digits while typing optionally
            this.value = this.value.replace(/[^\d]/g, '');
            validateZip4('zipcode');
        });
        zipEl.addEventListener('blur', function(){
            var v = (this.value || '').trim();
            if (v === '') { clearErrorMessage('zipcode'); return; }
            validateZip4('zipcode');
        });
    }

    // Password strength indicator and match status
    var passEl = document.getElementById('pass');
    var repassEl = document.getElementById('repass');
    var passStrengthSpan = document.getElementById('pass-strength');
    var repassMatchSpan = document.getElementById('repass-match');

    function getPasswordStrength(p) {
        var s = (p || '').replace(/\s+/g, ''); // ignore spaces for strength
        if (!s) return '';
        var types = 0;
        if (/[a-z]/.test(s)) types++;
        if (/[A-Z]/.test(s)) types++;
        if (/[0-9]/.test(s)) types++;
        if (/[^A-Za-z0-9]/.test(s)) types++;
        if (s.length < 8 || types < 2) return 'Weak';
        if (s.length >= 12 && types >= 4) return 'Strong';
        return 'Medium';
    }

    function hasSpace(str) { return /\s/.test(str || ''); }

    function updatePasswordStrength() {
        if (!passStrengthSpan) return;
        var val = (passEl && passEl.value) || '';
        var strength = getPasswordStrength(val);
        // If user typed only spaces or starts with a space, surface the space error immediately
        if (!strength) {
            passStrengthSpan.textContent = '';
            passStrengthSpan.style.color = '';
            passStrengthSpan.style.fontSize = '12px';
            if (hasSpace(val)) {
                showErrorMessage('pass', 'Spaces are not allowed in password.');
                return;
            }
            clearErrorMessage('pass');
            return;
        }
        // Update indicator first based on current strength
        passStrengthSpan.textContent = strength ? (strength + ' Password') : '';
        passStrengthSpan.style.color = strength === 'Strong' ? '#16a34a' : (strength === 'Medium' ? '#f59e0b' : '#dc2626');
        passStrengthSpan.style.fontSize = '11px';

        // Granular validations (ordered)
        // Spaces must be rejected regardless of strength
        if (hasSpace(val)) {
            showErrorMessage('pass', 'Spaces are not allowed in password.');
            return;
        }

        if (val.length < 8) {
            showErrorMessage('pass', 'Password must be at least 8 characters long.');
            return;
        }
        if (val.length > 50) {
            showErrorMessage('pass', 'Password cannot exceed 50 characters.');
            return;
        }
        if (!/[A-Z]/.test(val)) {
            showErrorMessage('pass', 'Password must contain at least 1 uppercase letter.');
            return;
        }
        if (!/[a-z]/.test(val)) {
            showErrorMessage('pass', 'Password must contain at least 1 lowercase letter.');
            return;
        }
        if (!/[0-9]/.test(val)) {
            showErrorMessage('pass', 'Password must contain at least 1 number.');
            return;
        }
        // Allow a broad set of symbols
        if (!/[^A-Za-z0-9]/.test(val)) {
            showErrorMessage('pass', 'Password must contain at least 1 special character.');
            return;
        }

        // All checks passed
        if (strength === 'Strong') {
            passStrengthSpan.textContent = 'Strong Password';
            passStrengthSpan.style.color = '#16a34a';
        } else if (strength === 'Medium') {
            passStrengthSpan.textContent = 'Medium Password';
            passStrengthSpan.style.color = '#f59e0b';
        } else {
            passStrengthSpan.textContent = 'Weak Password';
            passStrengthSpan.style.color = '#dc2626';
        }
        passStrengthSpan.style.fontSize = '11px';
        clearErrorMessage('pass');
    }

    function updatePasswordMatch() {
        if (!repassMatchSpan) return;
        var p1 = (passEl && passEl.value) || '';
        var p2 = (repassEl && repassEl.value) || '';
        if (!p2) { repassMatchSpan.textContent = ''; repassMatchSpan.style.color = ''; repassMatchSpan.style.fontSize = '11px'; return; }
        if (p1 === p2) { repassMatchSpan.textContent = 'Password Matched'; repassMatchSpan.style.color = '#16a34a'; repassMatchSpan.style.fontSize = '10px'; }
        else { repassMatchSpan.textContent = 'Password does not match'; repassMatchSpan.style.color = '#dc2626'; repassMatchSpan.style.fontSize = '10px'; }
    }

    function validatePasswordMismatchError() {
        var p1 = (passEl && passEl.value) || '';
        var p2 = (repassEl && repassEl.value) || '';
        if (!repassEl) return;
        if (p2 && p1 !== p2) {
            showErrorMessage('repass', '');
        } else {
            var err = document.getElementById('repass-error');
            if (err && /password does not match/i.test(err.textContent || '')) {
                err.parentNode && err.parentNode.removeChild(err);
            }
        }
    }

    // Server-side password reuse check (debounced)
    var passDupTimeoutId;
    function checkPasswordExists(password) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '../server/check_password.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (xhr.readyState == 4 && xhr.status == 200) {
                try {
                    var resp = JSON.parse(xhr.responseText || '{}');
                    if (resp && resp.exists === true) {
                        showErrorMessage('pass', 'This password is already used. Please choose a different password.');
                    } else {
                        // If the current error is the duplicate error, clear it.
                        var errEl = document.getElementById('pass-error');
                        if (errEl && /already used/i.test(errEl.textContent || '')) {
                            clearErrorMessage('pass');
                        }
                    }
                } catch (e) {
                    // Ignore parse errors
                }
            }
        };
        xhr.send('password=' + encodeURIComponent(password));
    }

    if (passEl) {
        passEl.addEventListener('input', function(){
            updatePasswordStrength();
            updatePasswordMatch();
            validatePasswordMismatchError();
            // Run duplicate check only when not Weak and length >= 8
            clearTimeout(passDupTimeoutId);
            var v = this.value || '';
            if (getPasswordStrength(v) !== 'Weak' && v.length >= 8 && !hasSpace(v)) {
                passDupTimeoutId = setTimeout(function(){ checkPasswordExists(v); }, 500);
            }
        });
        // Initialize on load in case of autofill
        updatePasswordStrength();
    }
    if (repassEl) {
        repassEl.addEventListener('input', function(){
            // If field is emptied, clear its error immediately (do not keep stale space/mismatch errors)
            if (((this.value || '').trim() === '')) {
                clearErrorMessage('repass');
            }
            updatePasswordMatch();
            validatePasswordMismatchError();
            // If spaces are present in either field, show a single error under Password
            var p1 = (passEl && passEl.value) || '';
            var p2 = (repassEl && repassEl.value) || '';
            if (hasSpace(p1) || hasSpace(p2)) {
                // Show space error only under the field that currently contains space
                if (hasSpace(p2)) {
                    showErrorMessage('repass', 'Spaces are not allowed in password.');
                }
                // Do not modify 'pass' errors here
            } else {
                // No spaces in either: clear only repass space error if present
                var repassErr = document.getElementById('repass-error');
                if (repassErr && /spaces are not allowed/i.test(repassErr.textContent || '')) {
                    repassErr.parentNode && repassErr.parentNode.removeChild(repassErr);
                }
                // Leave 'pass' errors untouched
            }
        }); 
    }

    // Email validation
    var emailEl = document.getElementById('email');
    if (emailEl) {
        var emailTimeoutId;
        emailEl.addEventListener('input', function(){
            // live validate but avoid required msg while typing
            clearTimeout(emailTimeoutId);
            validateEmail('email');
            // After format passes, debounce server check
            if (validateEmail('email')) {
                var that = this;
                emailTimeoutId = setTimeout(function(){
                    checkEmailExists(that.value);
                }, 500);
            }
        });
        emailEl.addEventListener('blur', function(){
            var raw = this.value || '';
            var v = raw.trim();
            // If field is truly empty (no characters), clear error; else if there are
            // any spaces, keep the space error instead of clearing it.
            if (v === '') {
                if (raw === '') {
                    clearErrorMessage('email');
                } else if (/\s/.test(raw)) {
                    showErrorMessage('email', 'Email should not contain spaces');
                }
                return;
            }
            clearTimeout(emailTimeoutId);
            if (validateEmail('email')) {
                checkEmailExists(this.value);
            }
        });
    }

    // Name fields validation
    var nameFields = ['fname', 'mname', 'lname'];
    for (var i = 0; i < nameFields.length; i++) {
        var field = document.getElementById(nameFields[i]);
        if (field) {
            // Prevent tabbing out if there's an error on this specific field
            field.addEventListener('keydown', function(e) {
                if (e.key === 'Tab' && document.getElementById(this.id + '-error')) {
                    e.preventDefault();
                    this.focus();
                }
            });
            field.addEventListener('input', function() {
                validateName(this.id);
            });
            field.addEventListener('blur', function() {
                validateName(this.id);
            });
        }
    }
    
    // Extension name validation (special handling for Roman numerals)
    var extField = document.getElementById('ename');
    if (extField) {
        // Show guidance on focus only when empty; otherwise keep specific errors
        extField.addEventListener('focus', function() {
            var raw = this.value || '';
            if (raw === '') {
                // No initial guidance message on focus when empty
                clearErrorMessage('ename');
            } else {
                validateExtensionName(this.id);
            }
        });
        extField.addEventListener('input', function() {
            var raw = this.value || '';
            var trimmed = raw.trim();
            if (raw === '') {
                showErrorMessage('ename');
                return;
            }
            // If user typed only spaces (or starts with a space), surface the specific space error immediately
            if (/\s/.test(raw) && trimmed === '') {
                showErrorMessage('ename', 'Spaces are not allowed');
                return;
            }
            validateExtensionName(this.id);
        });
        extField.addEventListener('blur', function() {
            var raw = this.value || '';
            var trimmed = raw.trim();
            if (raw === '') { clearErrorMessage('ename'); return; }
            if (trimmed === '') { showErrorMessage('ename', 'Spaces are not allowed'); return; }
            validateExtensionName(this.id);
        });
    }
    
    // Trim fields before form submission
    var form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            trimNameFields();
            // Final address validations
            var ok = true;
            for (var q = 0; q < placeFields.length; q++) {
                var id = placeFields[q].id, label = placeFields[q].label;
                ok = validateRequiredOnBlur(id, label) && validateNoSpacePlace(id, label) && ok;
            }
            // Email final validation
            ok = validateRequiredOnBlur('email', 'Email Address') && validateEmail('email') && ok;
            ok = validateRequiredOnBlur('zipcode', 'Zip Code') && validateZip4('zipcode') && ok;
            // Block if server-side duplicate error exists
            if (document.getElementById('email-error') && /exists/i.test(document.getElementById('email-error').textContent || '')) {
                ok = false;
            }
            if (!ok) e.preventDefault();
        });
    }
});
