<?php

namespace App\Services\Exams;

use RuntimeException;

/** El archivo no tiene la estructura esperada (columnas faltantes, vacío...). */
class InvalidResultFile extends RuntimeException {}
