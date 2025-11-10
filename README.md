# ExpoOne Template Engine

ExpoOne is a lightweight yet secure PHP templating engine that clearly separates presentation logic from application logic, faithfully replicating Template Version 1 from XpressEngine and Rhymix while being fully self-implemented. Its clean and expressive syntax lets developers embed dynamic content, conditionals, loops, and manage assets directly within HTML templates, while a built-in code validator ensures both flexibility and security for modern web development.

## Features

*   **Clean Syntax**: Intuitive syntax for variables, loops, and conditionals.
*   **Variable Filters**: Apply various filters (e.g., `upper`, `date`, `json`, `escapejs`, `link`) to variables for formatting and sanitization.
*   **Inline PHP**: Safely embed raw PHP code blocks using `{@ ... }` syntax, with security validation.
*   **HTML Directives**: Support for common control structures like `@if`, `@else`, `@foreach`, and `@while` embedded within HTML comments.
*   **Attribute-based Control**: `cond` and `loop` attributes for conditional rendering and iteration directly on HTML elements.
*   **Asset Management**: `<load>` and `<unload>` tags to automatically inject CSS and JavaScript files into the `<head>` or `<body>` of the rendered output, with sorting capabilities.
*   **Security Validation**: Built-in `Validator` prevents the use of dangerous PHP functions, superglobals, and shell execution within template code.

Usage is identical to existing XpressEngine templates. If you encounter any errors while using it, please submit an issue.

## Installation

Assuming you are using Composer, ensure the `ExpoOne` namespace is correctly configured for autoloading in your project.

1.  Place the `ExpoOne` directory (containing `Engine.php`, `Filter.php`, etc.) in your project's `src/` directory (or a similar location).
2.  Ensure your `composer.json` includes an autoloading configuration for the `ExpoOne` namespace, for example:

    ```json
    {
        "autoload": {
            "psr-4": {
                "ExpoOne\\": "src/"
            }
        }
    }
    ```
3.  Run `composer dump-autoload` to update your autoloader.

## Contributing
Contributions are welcome! Please feel free to submit issues or pull requests.

## License
This project is open-source and available under the GPL v2 License.
