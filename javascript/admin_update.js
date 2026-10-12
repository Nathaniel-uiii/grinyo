document.addEventListener('DOMContentLoaded', function() {
    var form = document.querySelector('.update-form');

    var createForm = document.querySelector('.create-form');
    if (createForm && typeof showErrorMessage === 'function') {
        var createUsername = document.getElementById('create-username');
        var createEmail = document.getElementById('create-email');
        var createPassword = document.getElementById('create-password');

        function validateCreateUsername() {
            var value = createUsername.value;
            if (!/^[a-z]{5,10}_[0-9]{1,6}$/.test(value)) {
                showErrorMessage(createUsername.id, 'Username format must be ex. xxxxx_12345');
                return false;
            }
            clearErrorMessage(createUsername.id);
            return true;
        }

        function validateCreatePassword() {
            var value = createPassword.value;
            if (/\s/.test(value)) {
                showErrorMessage(createPassword.id, 'Spaces are not allowed in password.');
                return false;
            }
            if (value.length < 8) {
                showErrorMessage(createPassword.id, 'Password must be at least 8 characters long.');
                return false;
            }
            if (value.length > 50) {
                showErrorMessage(createPassword.id, 'Password cannot exceed 50 characters.');
                return false;
            }
            if (!/[A-Z]/.test(value) || !/[a-z]/.test(value) || !/[0-9]/.test(value) || !/[^A-Za-z0-9]/.test(value)) {
                showErrorMessage(createPassword.id, 'Password needs uppercase, lowercase, number, and special character.');
                return false;
            }
            clearErrorMessage(createPassword.id);
            return true;
        }

        createUsername.addEventListener('input', validateCreateUsername);
        createUsername.addEventListener('blur', validateCreateUsername);
        createEmail.addEventListener('input', function() { validateEmail(createEmail.id); });
        createEmail.addEventListener('blur', function() { validateEmail(createEmail.id); });
        createPassword.addEventListener('input', validateCreatePassword);
        createPassword.addEventListener('blur', validateCreatePassword);

        createForm.addEventListener('submit', function(event) {
            var valid = validateCreateUsername();
            valid = validateEmail(createEmail.id) && valid;
            valid = validateCreatePassword() && valid;
            if (!valid) event.preventDefault();
        });
    }

    if (!form || typeof showErrorMessage !== 'function') return;

    function validateUpdateId() {
        var field = document.getElementById('update-user-id');
        var value = field.value;
        if (value === '') {
            clearErrorMessage(field.id);
            return true;
        }
        if (!/^\d+$/.test(value.replace('-', ''))) {
            showErrorMessage(field.id, 'ID must contain numbers only, with one hyphen.');
            return false;
        }
        if (!/^\d{4}-\d{4}$/.test(value)) {
            showErrorMessage(field.id, 'Please enter ID in format: xxxx-xxxx');
            return false;
        }
        clearErrorMessage(field.id);
        return true;
    }

    function filterUpdateId() {
        var field = document.getElementById('update-user-id');
        field.value = field.value.replace(/[^0-9-]/g, '');
        validateUpdateId();
    }

    function validateOptionalName(field) {
        if (field.value.trim() === '') {
            clearErrorMessage(field.id);
            return true;
        }
        return validateName(field.id);
    }

    function validateOptionalEmail(field) {
        if (field.value.trim() === '') {
            clearErrorMessage(field.id);
            return true;
        }
        return validateEmail(field.id);
    }

    function validateOptionalUsername(field) {
        var value = field.value;
        if (value === '') {
            clearErrorMessage(field.id);
            return true;
        }
        if (!/^[a-z]{5,10}_[0-9]{1,6}$/.test(value)) {
            showErrorMessage(field.id, 'Username format must be ex. xxxxx_12345');
            return false;
        }
        clearErrorMessage(field.id);
        return true;
    }

    var idField = document.getElementById('update-user-id');
    var firstNameField = document.getElementById('update-first-name');
    var lastNameField = document.getElementById('update-last-name');
    var usernameField = document.getElementById('update-username');
    var emailField = document.getElementById('update-email');

    idField.addEventListener('input', filterUpdateId);
    idField.addEventListener('blur', validateUpdateId);
    [firstNameField, lastNameField].forEach(function(field) {
        field.addEventListener('input', function() { validateOptionalName(field); });
        field.addEventListener('blur', function() { validateOptionalName(field); });
    });
    usernameField.addEventListener('input', function() { validateOptionalUsername(usernameField); });
    usernameField.addEventListener('blur', function() { validateOptionalUsername(usernameField); });
    emailField.addEventListener('input', function() { validateOptionalEmail(emailField); });
    emailField.addEventListener('blur', function() { validateOptionalEmail(emailField); });

    form.addEventListener('submit', function(event) {
        var valid = validateUpdateId();
        valid = validateOptionalName(firstNameField) && valid;
        valid = validateOptionalName(lastNameField) && valid;
        valid = validateOptionalUsername(usernameField) && valid;
        valid = validateOptionalEmail(emailField) && valid;
        if (!valid) event.preventDefault();
    });
});