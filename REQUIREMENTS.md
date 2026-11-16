# SIGO-CEPRE

# REQUERIMIENTOS DEL SISTEMA

## 🎓 Proyecto: **SIGO-CEPRE**

---

# 🧩 1. REQUERIMIENTOS FUNCIONALES (RF)

👉 Describen lo que el sistema **debe hacer**

---

## 🔐 1.1 Módulo de Autenticación

- **RF01:** El sistema debe permitir el inicio de sesión mediante autenticación con Google.
- **RF02:** El sistema debe registrar automáticamente nuevos usuarios autenticados.
- **RF03:** El sistema debe permitir el cierre de sesión seguro.
- **RF04:** El sistema debe gestionar sesiones activas mediante tokens.
- **RF05:** El sistema debe asignar roles (administrador, docente, estudiante).

---

## 👤 1.2 Módulo de Perfil de Usuario

- **RF06:** El sistema debe permitir al estudiante completar su perfil (incluyendo DNI).
- **RF07:** El sistema debe validar que el DNI sea único.
- **RF08:** El sistema debe permitir actualizar datos personales.
- **RF09:** El sistema debe mostrar el estado de perfil (completo/incompleto).

---

## 📝 1.3 Módulo de Matrícula

- **RF10:** El sistema debe permitir al estudiante realizar la matrícula mediante un formulario por pasos.
- **RF11:** El sistema debe guardar el progreso de la matrícula.
- **RF12:** El sistema debe validar la información ingresada en cada paso.
- **RF13:** El sistema debe permitir seleccionar carrera.
- **RF14:** El sistema debe permitir adjuntar documentos.
- **RF15:** El sistema debe registrar la matrícula en estado pendiente.
- **RF16:** El sistema debe permitir al administrador aprobar o rechazar matrículas.

---

## 💰 1.4 Módulo de Pagos

- **RF17:** El sistema debe permitir registrar pagos de matrícula.
- **RF18:** El sistema debe permitir subir comprobantes de pago.
- **RF19:** El sistema debe mostrar el estado del pago (pendiente, validado).
- **RF20:** El sistema debe permitir pagos parciales o completos.
- **RF21:** El sistema debe permitir al administrador validar pagos.
- **RF22:** El sistema debe generar historial de pagos por estudiante.

---

## 📅 1.5 Módulo de Horarios

- **RF23:** El sistema debe permitir al administrador crear horarios académicos.
- **RF24:** El sistema debe asignar horarios por carrera.
- **RF25:** El sistema debe permitir a los estudiantes visualizar su horario.
- **RF26:** El sistema debe permitir a los docentes visualizar sus clases.

---

## 📚 1.6 Módulo de Materiales

- **RF27:** El sistema debe permitir a los docentes subir materiales.
- **RF28:** El sistema debe permitir clasificar materiales por curso.
- **RF29:** El sistema debe permitir a los estudiantes descargar materiales.

---

## 🧪 1.7 Módulo de Evaluaciones

- **RF30:** El sistema debe permitir registrar exámenes.
- **RF31:** El sistema debe permitir importar resultados desde lector óptico.
- **RF32:** El sistema debe almacenar resultados por estudiante.
- **RF33:** El sistema debe permitir visualizar resultados.
- **RF34:** El sistema debe generar historial de notas.

---

## 📊 1.8 Módulo de Asistencia

- **RF35:** El sistema debe permitir al docente registrar asistencia.
- **RF36:** El sistema debe permitir visualizar asistencia por estudiante.
- **RF37:** El sistema debe generar reportes de asistencia.

---

## 📈 1.9 Módulo de Reportes

- **RF38:** El sistema debe generar reportes de matrícula.
- **RF39:** El sistema debe generar reportes de pagos.
- **RF40:** El sistema debe generar reportes académicos.
- **RF41:** El sistema debe permitir exportar reportes (PDF, Excel).

---

## 🌐 1.10 Módulo de Contenido (MongoDB)

- **RF42:** El sistema debe permitir publicar noticias.
- **RF43:** El sistema debe permitir gestionar comunicados.
- **RF44:** El sistema debe mostrar contenido en la página principal.
- **RF45:** El sistema debe permitir actualizar contenido dinámico.

---

## 🤖 1.11 Módulo Chatbot (Futuro)

- **RF46:** El sistema debe permitir consultas mediante chatbot.
- **RF47:** El chatbot debe responder preguntas frecuentes.
- **RF48:** El chatbot debe guiar al usuario en el proceso de matrícula.

---

# 🔒 2. REQUERIMIENTOS NO FUNCIONALES (RNF)

👉 Describen cómo debe comportarse el sistema

---

## 🖥️ 2.1 Usabilidad

- **RNF01:** El sistema debe ser intuitivo y fácil de usar.
- **RNF02:** El sistema debe ser responsive (móvil, tablet, PC).
- **RNF03:** El sistema debe mostrar mensajes claros al usuario.
- **RNF04:** El sistema debe minimizar la cantidad de pasos en procesos.

---

## ⚡ 2.2 Rendimiento

- **RNF05:** El sistema debe responder en menos de 3 segundos.
- **RNF06:** El sistema debe soportar múltiples usuarios concurrentes.
- **RNF07:** El sistema debe optimizar consultas a la base de datos.

---

## 🔐 2.3 Seguridad

- **RNF08:** El sistema debe implementar autenticación con Google OAuth.
- **RNF09:** El sistema debe usar tokens seguros (Sanctum).
- **RNF10:** El sistema debe validar entradas de usuario.
- **RNF11:** El sistema debe restringir accesos por roles.
- **RNF12:** El sistema debe proteger contra ataques comunes (XSS, CSRF).

---

## 🌐 2.4 Compatibilidad

- **RNF13:** El sistema debe funcionar en navegadores modernos.
- **RNF14:** El sistema debe ser compatible con distintos dispositivos.

---

## 🧩 2.5 Escalabilidad

- **RNF15:** El sistema debe permitir integración futura de IA (chatbot).
- **RNF16:** El sistema debe soportar crecimiento de usuarios.
- **RNF17:** El sistema debe permitir agregar nuevos módulos.

---

## 🛠️ 2.6 Mantenibilidad

- **RNF18:** El sistema debe tener arquitectura modular.
- **RNF19:** El código debe estar documentado.
- **RNF20:** El sistema debe permitir actualizaciones sin interrupciones.

---

## 💾 2.7 Disponibilidad

- **RNF21:** El sistema debe estar disponible al menos 95% del tiempo.
- **RNF22:** El sistema debe contar con respaldo de datos.

---

CEPRE UNAJMA
- Tres roles (admin, docente, estudiante)
- Contenido público dinámico
- Escalabilidad

🛠️ 🔥 ADMINISTRADOR (VERSIÓN MEJORADA)
🎯 Rol estratégico del sistema

El administrador no solo gestiona, sino que toma decisiones basadas en datos.

📊 🔹 FUNCIONES ACTUALIZADAS
🧑‍💼 Gestión general
Gestionar usuarios (estudiantes y docentes)
Validar matrículas
Administrar información del sistema
💳 Gestión de pagos
Verificar pagos realizados
Validar comprobantes
Detectar estudiantes con pagos pendientes
Controlar pagos parciales y completos
📝 Gestión académica
Registrar resultados de exámenes (lector óptico)
Publicar resultados
Supervisar desempeño académico
📅 Gestión operativa
Crear y asignar horarios
Supervisar asistencia
Controlar aulas y grupos
📢 Gestión de contenido
Publicar noticias
Publicar anuncios
Informar fechas importantes
📊 🔥 MÓDULO CLAVE: REPORTES (MUY IMPORTANTE)

Este es el módulo que le da peso a tu tesis.

📈 Tipos de reportes que debe generar:
🎓 Matrículas
Total de estudiantes matriculados
Matrículas por especialidad
Matrículas por periodo
💰 Pagos
Estudiantes que pagaron
Estudiantes con deuda
Pagos parciales vs completos
Ingresos totales
📊 Académico
Resultados por examen
Ranking de estudiantes
Promedios por aula o especialidad
📅 Asistencia
Porcentaje de asistencia por estudiante
Asistencia por aula
Estudiantes con bajo rendimiento
📉 Indicadores clave (Dashboard)
Total matriculados
Total pagos
Total pendientes
Promedio general
Tasa de asistencia
