<?php



namespace Lib\UI;

use Lib\Security\Sanitizer;

/**
 * Component Base - Clase base para componentes UI reutilizables
 * 
 * Sistema de componentes moderno inspirado en React/Vue
 * Caracter�sticas:
 * - Props type-safe
 * - Slots para contenido din�mico
 * - Auto-escape de XSS
 * - Accesibilidad (WCAG 2.1)
 * 
 * @package Lib\UI
 * @version 1.0.0
 */
abstract class Component
{
    protected array $props = [];
    protected array $slots = [];
    protected array $attributes = [];

    /**
     * Constructor
     * 
     * @param array $props Propiedades del componente
     */
    public function __construct(array $props = [])
    {
        $this->props = $this->validateProps($props);
    }

    /**
     * Valida props según schema del componente
     * 
     * @param array $props
     * @return array
     */
    protected function validateProps(array $props): array
    {
        $schema = $this->propSchema();
        $validated = [];

        foreach ($schema as $key => $rules) {
            $value = $props[$key] ?? $rules['default'] ?? null;

            // Validar tipo
            if (isset($rules['type']) && $value !== null) {
                $value = $this->castProp($value, $rules['type']);
            }

            // Validar requerido
            if (($rules['required'] ?? false) && $value === null) {
                throw new \InvalidArgumentException("Prop '{$key}' is required");
            }

            $validated[$key] = $value;
        }

        return $validated;
    }

    /**
     * Castea prop según tipo
     * 
     * @param mixed $value
     * @param string $type
     * @return mixed
     */
    private function castProp($value, string $type)
    {
        switch ($type) {
            case 'string': return (string)$value;
            case 'int': return (int)$value;
            case 'float': return (float)$value;
            case 'bool': return (bool)$value;
            case 'array': return (array)$value;
            default: return $value;
        }
    }

    /**
     * Schema de props (override en componentes hijos)
     * 
     * @return array
     */
    protected function propSchema(): array
    {
        return [];
    }

    /**
     * Agrega slot de contenido
     * 
     * @param string $name Nombre del slot
     * @param string $content Contenido HTML
     * @return self
     */
    public function slot(string $name, string $content): self
    {
        $this->slots[$name] = $content;
        return $this;
    }

    /**
     * Obtiene contenido de slot
     * 
     * @param string $name
     * @param string|null $default
     * @return string
     */
    protected function getSlot(string $name, ?string $default = null): string
    {
        return $this->slots[$name] ?? $default ?? '';
    }

    /**
     * Agrega atributo HTML
     * 
     * @param string $key
     * @param string|null $value
     * @return self
     */
    public function attr(string $key, ?string $value = null): self
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Renderiza atributos HTML
     * 
     * @param array $additional Atributos adicionales
     * @return string
     */
    protected function renderAttributes(array $additional = []): string
    {
        $attrs = array_merge($this->attributes, $additional);
        $html = [];

        foreach ($attrs as $key => $value) {
            if ($value === null) {
                $html[] = Sanitizer::escapeAttr($key);
            } else {
                $html[] = sprintf('%s="%s"', 
                    Sanitizer::escapeAttr($key), 
                    Sanitizer::escapeAttr($value)
                );
            }
        }

        return implode(' ', $html);
    }

    /**
     * Obtiene prop
     * 
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function prop(string $key, $default = null)
    {
        return $this->props[$key] ?? $default;
    }

    /**
     * Renderiza el componente
     * 
     * @return string HTML del componente
     */
    abstract public function render(): string;

    /**
     * Convierte componente a string
     * 
     * @return string
     */
    public function __toString(): string
    {
        try {
            return $this->render();
        } catch (\Throwable $e) {
            error_log("Component render error: " . $e->getMessage());
            return '<!-- Component render error -->';
        }
    }

    /**
     * Factory method para crear componente
     * 
     * @param array $props
     * @return static
     */
    public static function make(array $props = [])
    {
        return new static($props);
    }
}







