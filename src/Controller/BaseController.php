<?php
// src/Controller/BaseController.php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

abstract class BaseController
{
    /**
     * Renders a view template and returns it as a Response object.
     *
     * This method uses an output buffer to capture the output of a PHP template file,
     * then wraps it in a Symfony Response object.
     *
     * @param string $viewName The name of the template file (without .php extension).
     * @param array  $data     An array of data to make available to the view.
     * @return Response
     */
    protected function render(string $viewName, array $data = []): Response
    {
        // The extract function imports variables into the current symbol table from an array.
        // This makes the keys of the $data array available as variables in the template.
        extract($data);

        // Start output buffering
        ob_start();

        // Include the template files. The output is captured by the buffer.
        require_once __DIR__ . "/../../templates/_layout_header.php";
        require_once __DIR__ . "/../../templates/{$viewName}.php";
        require_once __DIR__ . "/../../templates/_layout_footer.php";

        // Get the contents of the buffer and clean it.
        $content = ob_get_clean();

        // Return a new Response object with the captured content.
        return new Response($content);
    }

    /**
     * Creates a RedirectResponse to a new URL.
     *
     * @param string $url The URL to redirect to.
     * @return RedirectResponse
     */
    protected function redirect(string $url): RedirectResponse
    {
        return new RedirectResponse($url);
    }
}
