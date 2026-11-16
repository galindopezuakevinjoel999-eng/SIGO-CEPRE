<?php

namespace App\Exceptions;

use RuntimeException;

/** No se pudo generar el código de matrícula por falta de datos (aula, semestre...). */
class EnrollmentCodeException extends RuntimeException {}
