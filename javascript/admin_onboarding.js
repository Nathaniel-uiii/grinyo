document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('.onboarding-form');
    if (!form) return;

    var questionSelects = form.querySelectorAll('select[name^="q"]');
    if (questionSelects.length) {
        function updateQuestionOptions() {
            var selectedQuestions = Array.prototype.map.call(questionSelects, function (select) {
                return select.value;
            }).filter(Boolean);

            questionSelects.forEach(function (select) {
                Array.prototype.forEach.call(select.options, function (option) {
                    option.disabled = option.value !== '' && option.value !== select.value && selectedQuestions.indexOf(option.value) !== -1;
                });
            });
        }

        questionSelects.forEach(function (select) {
            select.addEventListener('change', updateQuestionOptions);
        });
        updateQuestionOptions();
    }

    var onboardingPassword = document.getElementById('onboarding-password');
    var onboardingPasswordStrength = document.getElementById('onboarding-password-strength');
    if (onboardingPassword && onboardingPasswordStrength) {
        function passwordStrength(value) {
            var types = 0;
            if (/[a-z]/.test(value)) types++;
            if (/[A-Z]/.test(value)) types++;
            if (/\d/.test(value)) types++;
            if (/[^A-Za-z0-9]/.test(value)) types++;
            if (!value) return '';
            if (value.length < 8 || types < 2) return 'Weak';
            if (value.length >= 12 && types === 4 && !/\s/.test(value)) return 'Strong';
            return 'Medium';
        }

        function updatePasswordStrength() {
            var value = onboardingPassword.value;
            var strength = passwordStrength(value);
            onboardingPasswordStrength.textContent = strength ? strength + ' password' : '';
            onboardingPasswordStrength.className = 'password-strength' + (strength ? ' password-' + strength.toLowerCase() : '');
            onboardingPassword.setCustomValidity(value && (value.length < 8 || value.length > 50 || /\s/.test(value) || !/[A-Z]/.test(value) || !/[a-z]/.test(value) || !/\d/.test(value) || !/[^A-Za-z0-9]/.test(value)) ? 'Use 8-50 characters with uppercase, lowercase, number, special character, and no spaces.' : '');
        }

        onboardingPassword.addEventListener('input', updatePasswordStrength);
        updatePasswordStrength();
    }

    if (!document.getElementById('onboarding-date-of-birth')) return;

    function showError(field, message) {
        var existing = document.getElementById(field.id + '-error');
        if (existing) existing.remove();
        var error = document.createElement('small');
        error.id = field.id + '-error';
        error.className = 'onboarding-error';
        error.textContent = message;
        field.insertAdjacentElement('afterend', error);
    }

    function clearError(field) {
        var error = document.getElementById(field.id + '-error');
        if (error) error.remove();
    }

    function validateName(field, required) {
        var value = field.value;
        if (!value.trim() && !required) { clearError(field); return true; }
        if (!value.trim() && required) { showError(field, 'This field is required.'); return false; }
        if (/^\s/.test(value)) {
            showError(field, 'Spaces should not be inputted first.');
            return false;
        }
        if (/^[a-z]/.test(value)) {
            showError(field, 'First letter must be capital.');
            return false;
        }
        if (!/^[A-Z][a-z]*(?: [A-Z][a-z]*)*$/.test(value)) {
            showError(field, 'Use letters with proper capitalization and single spaces only.');
            return false;
        }
        clearError(field);
        return true;
    }

    function validatePlace(field, label) {
        var value = field.value;
        if (!value.trim() || /^\s/.test(value) || /\s{2,}/.test(value)) {
            showError(field, label + ' is required and cannot start with or contain double spaces.');
            return false;
        }
        if (field.id === 'onboarding-barangay' && !/^Barangay(?: \d+| [A-Za-z][A-Za-z -]*)$/.test(value)) {
            showError(field, 'Please use the format Barangay 1 or Barangay Name.');
            return false;
        }
        if (field.id === 'onboarding-street' && !/^Purok \d+$/.test(value)) {
            showError(field, 'Purok/Street must use the format Purok 1.');
            return false;
        }
        if (!/^[A-Za-z0-9]+(?:[ .'-][A-Za-z0-9]+)*$/.test(value)) {
            showError(field, label + ' contains invalid characters.');
            return false;
        }
        clearError(field);
        return true;
    }

    function validateField(field, validator) {
        field.addEventListener('input', function () { validator(field); });
        field.addEventListener('blur', function () { validator(field); });
    }

    var requiredNames = ['onboarding-first-name', 'onboarding-last-name'];
    requiredNames.forEach(function (id) { var field = document.getElementById(id); validateField(field, function (item) { return validateName(item, true); }); });
    var middle = document.getElementById('onboarding-middle-name');
    var extension = document.getElementById('onboarding-extension-name');
    validateField(middle, function (field) { return validateName(field, false); });
    validateField(extension, function (field) { return validateName(field, false); });

    var email = document.getElementById('onboarding-email');
    validateField(email, function (field) {
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value)) { showError(field, 'Enter a valid email address.'); return false; }
        clearError(field); return true;
    });

    var placeLabels = { street: 'Purok/Street', barangay: 'Barangay', 'city-municipality': 'City/Municipality', province: 'Province', country: 'Country', 'zip-code': 'ZIP Code' };
    ['street', 'barangay', 'city-municipality', 'province', 'country'].forEach(function (suffix) {
        var field = document.getElementById('onboarding-' + suffix);
        validateField(field, function (item) { return validatePlace(item, placeLabels[suffix]); });
    });
    var zip = document.getElementById('onboarding-zip-code');
    validateField(zip, function (field) {
        field.value = field.value.replace(/\D/g, '').slice(0, 4);
        if (!/^\d{4}$/.test(field.value)) { showError(field, 'ZIP Code must be 4 digits.'); return false; }
        clearError(field); return true;
    });

    var birthDate = document.getElementById('onboarding-date-of-birth');
    var age = document.getElementById('onboarding-age');
    function updateAge() {
        var date = new Date(birthDate.value);
        if (Number.isNaN(date.getTime()) || date > new Date()) { age.value = ''; showError(birthDate, 'Enter a valid date of birth.'); return false; }
        var today = new Date();
        var value = today.getFullYear() - date.getFullYear();
        if (today.getMonth() < date.getMonth() || (today.getMonth() === date.getMonth() && today.getDate() < date.getDate())) value--;
        age.value = value > 0 ? value : '';
        clearError(birthDate);
        return value >= 18;
    }
    birthDate.addEventListener('change', updateAge);
    birthDate.addEventListener('blur', updateAge);
    var sex = document.getElementById('onboarding-sex');

    form.addEventListener('submit', function (event) {
        var valid = updateAge();
        requiredNames.forEach(function (id) { valid = validateName(document.getElementById(id), true) && valid; });
        valid = validateName(middle, false) && valid;
        valid = validateName(extension, false) && valid;
        valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value) && valid;
        ['street', 'barangay', 'city-municipality', 'province', 'country'].forEach(function (suffix) { valid = validatePlace(document.getElementById('onboarding-' + suffix), placeLabels[suffix]) && valid; });
        valid = /^\d{4}$/.test(zip.value) && valid;
        if (!sex.value) { showError(sex, 'This field is required.'); valid = false; } else { clearError(sex); }
        if (!valid) event.preventDefault();
    });
});
