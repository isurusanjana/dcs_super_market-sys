<?php
class AttendanceController extends Controller {
    protected ?string $module = 'admin';
    function action_index(): void {
        $this->need('admin.attendance.view'); $date = (string)input('date', date('Y-m-d'));
        if (Validator::check(['d' => $date], ['d' => ['label' => 'Date', 'rules' => 'date']])) $date = date('Y-m-d');
        $emps = DB::all("SELECT e.id, e.emp_no, e.full_name, a.status, a.check_in, a.check_out, a.note FROM employees e LEFT JOIN attendance a ON a.employee_id=e.id AND a.att_date=? WHERE e.status='active' ORDER BY e.full_name", [$date]);
        $this->render('attendance/index', ['title' => 'Attendance', 'date' => $date, 'emps' => $emps]);
    }
    function action_save(): void {
        $this->postOnly(); $this->need('admin.attendance.record'); $date = (string)input('date');
        if (Validator::check(['d' => $date], ['d' => ['label' => 'Date', 'rules' => 'required|date']])) { flash('danger', 'Invalid date.'); redirect('attendance/index'); }
        if ($date > date('Y-m-d')) { flash('danger', 'Cannot record attendance for a future date.'); redirect('attendance/index', ['date' => $date]); }
        $n = 0; $bad = 0;
        foreach ((array)($_POST['status'] ?? []) as $eid => $st) {
            $eid = (int)$eid; if ($st === '' || !in_array($st, ['present', 'absent', 'late', 'leave', 'half_day'], true)) continue;
            if (!DB::val("SELECT 1 FROM employees WHERE id=? AND status='active'", [$eid])) continue;
            $in = trim((string)($_POST['in'][$eid] ?? '')) ?: null; $out = trim((string)($_POST['out'][$eid] ?? '')) ?: null; $note = trim((string)($_POST['note'][$eid] ?? '')) ?: null;
            if (($in && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in)) || ($out && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $out)) || ($in && $out && $out < $in) || ($note && mb_strlen($note) > 255)) { $bad++; continue; }
            DB::q('INSERT INTO attendance (employee_id, att_date, check_in, check_out, status, note, recorded_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE check_in=VALUES(check_in), check_out=VALUES(check_out), status=VALUES(status), note=VALUES(note), recorded_by=VALUES(recorded_by)', [$eid, $date, $in, $out, $st, $note, Auth::id()]); $n++;
        }
        audit('attendance', 'attendance', null, ['date' => $date, 'rows' => $n]);
        flash($bad ? 'warning' : 'success', "$n record(s) saved." . ($bad ? " $bad row(s) skipped due to invalid times." : '')); redirect('attendance/index', ['date' => $date]);
    }
    function action_history(): void {
        $this->need('admin.attendance.view'); $eid = (int)input('employee', 0); $from = (string)input('from', date('Y-m-01')); $to = (string)input('to', date('Y-m-d'));
        $emp = $eid ? DB::one('SELECT * FROM employees WHERE id=?', [$eid]) : null;
        $rows = $emp ? DB::all('SELECT * FROM attendance WHERE employee_id=? AND att_date BETWEEN ? AND ? ORDER BY att_date DESC', [$eid, $from, $to]) : [];
        $this->render('attendance/history', ['title' => 'Attendance history', 'emp' => $emp, 'rows' => $rows, 'from' => $from, 'to' => $to, 'eid' => $eid, 'employees' => DB::all('SELECT id, emp_no, full_name FROM employees ORDER BY full_name')]);
    }
}
