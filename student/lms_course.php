<?php
require_once '../includes/lms_access.php';

$lmsStudent = requireLmsAccess(['student']);
$subjectId = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT);

$course = $subjectId
    ? fetchLmsSubjectForStudent($pdo, (int)$lmsStudent['student_id'], $subjectId)
    : null;

if (!$course) {
    $_SESSION['flash_error'] = 'That subject is not part of your confirmed enrollment.';
    header('Location: lms');
    exit;
}

$courseSections = [
    'overview' => 'Overview',
    'announcements' => 'Announcements',
    'lessons' => 'Lessons / Modules',
    'materials' => 'Learning Materials',
    'assignments' => 'Assignments',
    'quizzes' => 'Quizzes / Exams',
    'grades' => 'Grades',
    'progress' => 'Course Progress',
];
$activeSection = (string)($_GET['section'] ?? 'overview');
if (!isset($courseSections[$activeSection])) {
    $activeSection = 'overview';
}

$courseGrades = [];
if (in_array($activeSection, ['grades', 'progress'], true)) {
    $courseGrades = fetchLmsCourseGrades($pdo, (int)$lmsStudent['student_id'], (int)$course['subject_id']);
}
$lessonModules = [];
$selectedLesson = null;
if ($activeSection === 'lessons') {
    $lessonRows = fetchLmsModulesForStudentSubject($pdo, (int)$lmsStudent['student_id'], (int)$course['subject_id']);
    foreach ($lessonRows as $row) {
        $moduleId = (int)$row['module_id'];
        if (!isset($lessonModules[$moduleId])) {
            $lessonModules[$moduleId] = [
                'id' => $moduleId,
                'title' => $row['module_title'],
                'description' => $row['module_description'],
                'lessons' => [],
            ];
        }
        if ($row['lesson_id'] !== null) {
            $lessonModules[$moduleId]['lessons'][] = [
                'id' => (int)$row['lesson_id'],
                'title' => $row['lesson_title'],
                'content_type' => $row['content_type'],
            ];
        }
    }

    $lessonId = filter_input(INPUT_GET, 'lesson_id', FILTER_VALIDATE_INT);
    if (!$lessonId) {
        foreach ($lessonModules as $module) {
            if ($module['lessons']) {
                $lessonId = $module['lessons'][0]['id'];
                break;
            }
        }
    }
    if ($lessonId) {
        $selectedLesson = fetchLmsLessonForStudentSubject(
            $pdo,
            (int)$lmsStudent['student_id'],
            (int)$course['subject_id'],
            (int)$lessonId
        );
        if (!$selectedLesson) {
            http_response_code(404);
        }
    }
}
$completedGradeCheckpoints = 0;
foreach ($courseGrades as $grade) {
    $completedGradeCheckpoints = max(
        $completedGradeCheckpoints,
        (int)($grade['prelim_grade'] !== null)
            + (int)($grade['midterm_grade'] !== null)
            + (int)($grade['final_exam_grade'] !== null)
    );
}
$courseProgressPercent = lmsCourseProgressPercent($completedGradeCheckpoints);

$page_title = $course['subject_code'] . ' — ' . $courseSections[$activeSection];
require_once '../includes/header.php';
?>

<p class="mb-3"><a href="lms" class="text-decoration-none"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>My Courses</a></p>
<div class="mb-4">
    <div class="text-uppercase small fw-bold text-brand-primary"><?php echo htmlspecialchars($course['subject_code']); ?></div>
    <h1 class="h3 fw-bold text-navy-alt mt-1 mb-1"><?php echo htmlspecialchars($course['subject_name']); ?></h1>
    <p class="text-muted mb-0"><?php echo htmlspecialchars(($course['section_name'] ?: 'Confirmed section') . ' · ' . $course['school_year'] . ' / ' . $course['semester']); ?></p>
</div>

<nav class="nav nav-tabs flex-nowrap overflow-auto mb-4" aria-label="Course sections">
    <?php foreach ($courseSections as $sectionKey => $sectionLabel): ?>
        <a class="nav-link text-nowrap <?php echo $activeSection === $sectionKey ? 'active' : ''; ?>"
           href="lms_course?subject_id=<?php echo (int)$course['subject_id']; ?>&amp;section=<?php echo htmlspecialchars($sectionKey); ?>"
           <?php echo $activeSection === $sectionKey ? 'aria-current="page"' : ''; ?>>
            <?php echo htmlspecialchars($sectionLabel); ?>
        </a>
    <?php endforeach; ?>
</nav>

<section class="border rounded bg-white p-4 p-lg-5" aria-labelledby="course-section-heading">
    <h2 class="h5 fw-bold text-navy-alt mb-4" id="course-section-heading"><?php echo htmlspecialchars($courseSections[$activeSection]); ?></h2>

    <?php if ($activeSection === 'overview'): ?>
        <dl class="row mb-0">
            <dt class="col-sm-3">Section</dt>
            <dd class="col-sm-9"><?php echo htmlspecialchars($course['section_name'] ?: 'Confirmed section'); ?></dd>
            <dt class="col-sm-3">Academic term</dt>
            <dd class="col-sm-9"><?php echo htmlspecialchars($course['school_year'] . ' / ' . $course['semester']); ?></dd>
            <dt class="col-sm-3">Units</dt>
            <dd class="col-sm-9"><?php echo htmlspecialchars((string)$course['units']); ?></dd>
            <dt class="col-sm-3">Instructor</dt>
            <dd class="col-sm-9">
                <?php echo htmlspecialchars($course['instructor_name'] ?: 'Not assigned'); ?>
                <?php if (!empty($course['instructor_email'])): ?>
                    <div><a href="mailto:<?php echo htmlspecialchars($course['instructor_email']); ?>"><?php echo htmlspecialchars($course['instructor_email']); ?></a></div>
                <?php endif; ?>
            </dd>
            <dt class="col-sm-3">Schedule</dt>
            <dd class="col-sm-9"><?php echo htmlspecialchars(trim(($course['day_of_week'] ?? '') . ' ' . ($course['start_time'] ?? '') . ' - ' . ($course['end_time'] ?? '')) ?: 'Not scheduled'); ?></dd>
            <dt class="col-sm-3">Room</dt>
            <dd class="col-sm-9 mb-0"><?php echo htmlspecialchars($course['room'] ?: 'Not assigned'); ?></dd>
        </dl>
    <?php elseif ($activeSection === 'grades'): ?>
        <?php if ($courseGrades): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Prelim</th><th>Midterm</th><th>Final Exam</th><th>Final Grade</th><th>Remarks</th></tr></thead>
                    <tbody>
                        <?php foreach ($courseGrades as $grade): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($grade['prelim_grade'] ?? '—')); ?></td>
                                <td><?php echo htmlspecialchars((string)($grade['midterm_grade'] ?? '—')); ?></td>
                                <td><?php echo htmlspecialchars((string)($grade['final_exam_grade'] ?? '—')); ?></td>
                                <td class="fw-semibold"><?php echo htmlspecialchars((string)($grade['final_grade'] ?? '—')); ?></td>
                                <td><?php echo htmlspecialchars($grade['remarks'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">No Registrar-approved grades are available for this subject yet.</p>
        <?php endif; ?>
    <?php elseif ($activeSection === 'progress'): ?>
        <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
            <span class="fw-semibold">Approved grade checkpoints</span>
            <span><?php echo $completedGradeCheckpoints; ?> of 3 · <?php echo $courseProgressPercent; ?>%</span>
        </div>
        <div class="progress mb-2" role="progressbar" aria-label="Approved grade checkpoint progress" aria-valuenow="<?php echo $courseProgressPercent; ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" style="width: <?php echo $courseProgressPercent; ?>%; background-color: var(--brand-primary);"></div>
        </div>
        <p class="small text-muted mb-0">Based on Registrar-approved prelim, midterm, and final exam grades.</p>
    <?php elseif ($activeSection === 'lessons'): ?>
        <?php if (!$lessonModules): ?>
            <p class="text-muted mb-0">No published lessons are available for this subject yet.</p>
        <?php else: ?>
            <div class="row g-4">
                <aside class="col-12 col-lg-4" aria-label="Modules and lessons">
                    <?php foreach ($lessonModules as $module): ?>
                        <div class="mb-4">
                            <h3 class="h6 fw-bold mb-1"><?php echo htmlspecialchars($module['title']); ?></h3>
                            <?php if (!empty($module['description'])): ?>
                                <p class="small text-muted mb-2"><?php echo nl2br(htmlspecialchars($module['description'])); ?></p>
                            <?php endif; ?>
                            <?php if ($module['lessons']): ?>
                                <ul class="list-unstyled mb-0">
                                    <?php foreach ($module['lessons'] as $lesson): ?>
                                        <li>
                                            <a class="d-block py-2 px-2 rounded text-decoration-none <?php echo (int)($selectedLesson['id'] ?? 0) === $lesson['id'] ? 'bg-light fw-semibold text-dark' : ''; ?>"
                                               href="lms_course?subject_id=<?php echo (int)$course['subject_id']; ?>&amp;section=lessons&amp;lesson_id=<?php echo $lesson['id']; ?>">
                                                <?php echo htmlspecialchars($lesson['title']); ?>
                                                <span class="d-block small text-muted"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $lesson['content_type']))); ?></span>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="small text-muted mb-0">No published lessons in this module.</p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </aside>
                <div class="col-12 col-lg-8">
                    <?php if (!$selectedLesson): ?>
                        <div class="border rounded p-4 bg-light h-100">
                            <p class="text-muted mb-0">Choose a lesson to view its content.</p>
                        </div>
                    <?php elseif (!$selectedLesson['id']): ?>
                        <div class="alert alert-warning mb-0">That lesson is not available in your enrolled subject.</div>
                    <?php else: ?>
                        <article>
                            <div class="small text-muted mb-1"><?php echo htmlspecialchars($selectedLesson['module_title']); ?></div>
                            <h3 class="h5 fw-bold"><?php echo htmlspecialchars($selectedLesson['title']); ?></h3>
                            <?php if (!empty($selectedLesson['description'])): ?>
                                <p><?php echo nl2br(htmlspecialchars($selectedLesson['description'])); ?></p>
                            <?php endif; ?>
                            <?php if ($selectedLesson['content_type'] === 'text'): ?>
                                <?php if (!empty($selectedLesson['content_body'])): ?>
                                    <div><?php echo nl2br(htmlspecialchars($selectedLesson['content_body'])); ?></div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">Lesson content has not been added yet.</p>
                                <?php endif; ?>
                            <?php elseif ($selectedLesson['content_type'] === 'external_link'): ?>
                                <?php
                                    $externalUrl = trim((string)$selectedLesson['content_path']);
                                    $externalScheme = strtolower((string)parse_url($externalUrl, PHP_URL_SCHEME));
                                    $externalUrlIsAllowed = filter_var($externalUrl, FILTER_VALIDATE_URL)
                                        && in_array($externalScheme, ['http', 'https'], true);
                                ?>
                                <?php if ($externalUrlIsAllowed): ?>
                                    <a class="btn btn-brand-primary" href="<?php echo htmlspecialchars($externalUrl); ?>" target="_blank" rel="noopener noreferrer">
                                        Open learning link <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i>
                                    </a>
                                <?php else: ?>
                                    <p class="text-muted mb-0">This learning link is unavailable.</p>
                                <?php endif; ?>
                            <?php elseif (in_array($selectedLesson['content_type'], ['image', 'presentation', 'pdf', 'video'], true) && !empty($selectedLesson['content_path'])): ?>
                                <?php $lessonFileUrl = 'lms_lesson_file.php?subject_id=' . (int)$course['subject_id'] . '&amp;lesson_id=' . (int)$selectedLesson['id']; ?>
                                <?php if ($selectedLesson['content_type'] === 'image'): ?>
                                    <img class="img-fluid rounded" src="<?php echo $lessonFileUrl; ?>" alt="<?php echo htmlspecialchars($selectedLesson['title']); ?>">
                                <?php elseif ($selectedLesson['content_type'] === 'pdf'): ?>
                                    <div class="ratio" style="--bs-aspect-ratio: 75%;">
                                        <iframe src="<?php echo $lessonFileUrl; ?>" title="<?php echo htmlspecialchars($selectedLesson['title']); ?>" loading="lazy"></iframe>
                                    </div>
                                    <a class="d-inline-block mt-2" href="<?php echo $lessonFileUrl; ?>" target="_blank" rel="noopener noreferrer">Open PDF in a new tab</a>
                                <?php elseif ($selectedLesson['content_type'] === 'video'): ?>
                                    <video class="w-100 rounded" controls preload="metadata">
                                        <source src="<?php echo $lessonFileUrl; ?>">
                                        Your browser does not support this video format.
                                    </video>
                                <?php else: ?>
                                    <a class="btn btn-outline-secondary" href="<?php echo $lessonFileUrl; ?>" target="_blank" rel="noopener noreferrer">
                                        Open presentation <i class="bi bi-box-arrow-up-right ms-1" aria-hidden="true"></i>
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-muted mb-0">Lesson content has not been added yet.</p>
                            <?php endif; ?>
                        </article>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <p class="text-muted mb-0">This course area is not available yet.</p>
    <?php endif; ?>
</section>

<?php require_once '../includes/footer.php'; ?>