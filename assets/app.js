import './bootstrap.js';
// Required for stateless CSRF (login, product forms, etc.) — must load eagerly, not via lazy Stimulus
import './controllers/csrf_protection_controller.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import './styles/app.css';
