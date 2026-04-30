#!/usr/bin/env python3
"""
DQ Rules Schedule Service - Simple Version
Runs existing DataRover quality rules on schedule
"""

from flask import Flask, request, jsonify
from apscheduler.schedulers.background import BackgroundScheduler
from apscheduler.triggers.cron import CronTrigger
import os
import sqlite3
import requests
import json
from datetime import datetime
import logging

# Configuration — overridable via env (set by docker-compose)
DATAROVER_URL = os.environ.get("DATAROVER_URL", "http://localhost/datarover/backend.php")
DB_FILE = os.environ.get("DQ_DB_PATH", "dq_schedules.db")
PORT = int(os.environ.get("PORT", "8001"))

# Setup
app = Flask(__name__)
logging.basicConfig(level=logging.INFO)
scheduler = BackgroundScheduler()

# ============================================================
# DATABASE SETUP
# ============================================================

def init_db():
    """Initialize SQLite database"""
    conn = sqlite3.connect(DB_FILE)
    cursor = conn.cursor()
    
    # Schedules table - SIMPLE!
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            rule_ids TEXT NOT NULL,
            frequency TEXT NOT NULL,
            run_time TEXT NOT NULL,
            notify_email TEXT,
            enabled INTEGER DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    """)
    
    # Executions table
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS executions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            schedule_id INTEGER NOT NULL,
            schedule_name TEXT,
            status TEXT DEFAULT 'running',
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP,
            total_rules INTEGER DEFAULT 0,
            passed_rules INTEGER DEFAULT 0,
            failed_rules INTEGER DEFAULT 0,
            error_message TEXT,
            FOREIGN KEY (schedule_id) REFERENCES schedules(id)
        )
    """)
    
    # Add schedule_name column if not exists (for existing databases)
    try:
        cursor.execute("ALTER TABLE executions ADD COLUMN schedule_name TEXT")
    except:
        pass  # Column already exists
    
    # Rule results table
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS rule_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            execution_id INTEGER NOT NULL,
            rule_id INTEGER NOT NULL,
            rule_name TEXT,
            status TEXT,
            passed_checks INTEGER DEFAULT 0,
            failed_checks INTEGER DEFAULT 0,
            total_checks INTEGER DEFAULT 0,
            execution_time REAL,
            error_message TEXT,
            FOREIGN KEY (execution_id) REFERENCES executions(id)
        )
    """)
    
    conn.commit()
    
    # Migrate: Update existing executions with schedule_name from schedules table
    cursor.execute("""
        UPDATE executions 
        SET schedule_name = (
            SELECT name FROM schedules WHERE schedules.id = executions.schedule_id
        )
        WHERE schedule_name IS NULL
    """)
    conn.commit()
    
    conn.close()
    logging.info("Database initialized")

# ============================================================
# SCHEDULE EXECUTION
# ============================================================

def execute_schedule(schedule_id):
    """Execute a schedule - Run selected quality rules"""
    logging.info(f"=== EXECUTING SCHEDULE {schedule_id} ===")
    
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    cursor = conn.cursor()
    
    execution_id = None
    
    try:
        # Get schedule
        cursor.execute("SELECT * FROM schedules WHERE id = ?", (schedule_id,))
        schedule = cursor.fetchone()
        
        if not schedule:
            logging.error(f"Schedule {schedule_id} not found")
            return
            
        if not schedule['enabled']:
            logging.warning(f"Schedule {schedule_id} is disabled")
            return
        
        logging.info(f"Schedule name: {schedule['name']}")
        logging.info(f"Rule IDs raw: {schedule['rule_ids']}")
        
        # Parse rule IDs
        try:
            rule_ids = json.loads(schedule['rule_ids'])
        except json.JSONDecodeError as e:
            logging.error(f"Failed to parse rule_ids: {e}")
            rule_ids = []
        
        logging.info(f"Rule IDs parsed: {rule_ids}")
        
        if not rule_ids:
            logging.error(f"No rules found for schedule {schedule_id}")
            # Create failed execution record
            cursor.execute("""
                INSERT INTO executions (schedule_id, schedule_name, total_rules, status, error_message)
                VALUES (?, ?, 0, 'failed', 'No rules selected')
            """, (schedule_id, schedule['name']))
            conn.commit()
            return
        
        # Create execution record
        cursor.execute("""
            INSERT INTO executions (schedule_id, schedule_name, total_rules, status)
            VALUES (?, ?, ?, 'running')
        """, (schedule_id, schedule['name'], len(rule_ids)))
        execution_id = cursor.lastrowid
        conn.commit()
        logging.info(f"Created execution record: {execution_id}")
        
        # Call DataRover to run selected SODA checks
        url = f"{DATAROVER_URL}?action=run_selected_checks"
        payload = {'check_ids': rule_ids}
        
        logging.info(f"Calling DataRover: {url}")
        logging.info(f"Payload: {payload}")
        
        try:
            response = requests.post(
                url,
                json=payload,
                timeout=300,
                headers={'Content-Type': 'application/json'}
            )
            
            logging.info(f"Response status: {response.status_code}")
            logging.info(f"Response body: {response.text[:500] if response.text else 'empty'}")
            
            result = response.json()
            
        except requests.exceptions.Timeout:
            logging.error("Request timeout")
            cursor.execute("""
                UPDATE executions 
                SET status = 'failed', completed_at = CURRENT_TIMESTAMP, error_message = ?
                WHERE id = ?
            """, ("Request timeout after 300 seconds", execution_id))
            conn.commit()
            return
            
        except requests.exceptions.ConnectionError as e:
            logging.error(f"Connection error: {e}")
            cursor.execute("""
                UPDATE executions 
                SET status = 'failed', completed_at = CURRENT_TIMESTAMP, error_message = ?
                WHERE id = ?
            """, (f"Connection error: {str(e)}", execution_id))
            conn.commit()
            return
            
        except json.JSONDecodeError as e:
            logging.error(f"Failed to parse response: {e}")
            cursor.execute("""
                UPDATE executions 
                SET status = 'failed', completed_at = CURRENT_TIMESTAMP, error_message = ?
                WHERE id = ?
            """, (f"Invalid JSON response: {str(e)}", execution_id))
            conn.commit()
            return
        
        if result.get('success'):
            # Handle nested response - data may be inside 'data' key
            actual_result = result.get('data', result)
            if isinstance(actual_result, dict) and 'success' in actual_result:
                # Response is nested: {"success": true, "data": {"success": true, "passed": 1, ...}}
                actual_result = actual_result
            
            # Save results
            results = actual_result.get('results', [])
            passed = actual_result.get('passed', 0)
            failed = actual_result.get('failed', 0)
            
            logging.info(f"DataRover response - passed: {passed}, failed: {failed}, results count: {len(results)}")
            
            # Update execution
            cursor.execute("""
                UPDATE executions 
                SET status = 'completed',
                    completed_at = CURRENT_TIMESTAMP,
                    passed_rules = ?,
                    failed_rules = ?
                WHERE id = ?
            """, (passed, failed, execution_id))
            
            logging.info(f"Updated execution {execution_id} with passed={passed}, failed={failed}")
            
            # Save individual check results
            for r in results:
                logging.info(f"Saving result for check: {r}")
                cursor.execute("""
                    INSERT INTO rule_results (
                        execution_id, rule_id, rule_name, status,
                        passed_checks, failed_checks, total_checks,
                        execution_time, error_message
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                """, (
                    execution_id,
                    r.get('check_id'),
                    r.get('check_name'),
                    r.get('outcome', 'unknown'),
                    1 if r.get('outcome') == 'pass' else 0,
                    1 if r.get('outcome') == 'fail' else 0,
                    1,
                    r.get('duration_ms', 0) / 1000 if r.get('duration_ms') else None,
                    r.get('error')
                ))
            
            conn.commit()
            logging.info(f"=== Schedule {schedule_id} completed successfully ===")
            
        else:
            # Execution failed
            error = result.get('error', result.get('message', 'Unknown error'))
            logging.error(f"DataRover returned error: {error}")
            
            cursor.execute("""
                UPDATE executions 
                SET status = 'failed',
                    completed_at = CURRENT_TIMESTAMP,
                    error_message = ?
                WHERE id = ?
            """, (error, execution_id))
            conn.commit()
            
    except Exception as e:
        logging.error(f"Error executing schedule {schedule_id}: {str(e)}")
        import traceback
        traceback.print_exc()
        
        if execution_id:
            cursor.execute("""
                UPDATE executions 
                SET status = 'failed',
                    completed_at = CURRENT_TIMESTAMP,
                    error_message = ?
                WHERE id = ?
            """, (str(e), execution_id))
            conn.commit()
        
    finally:
        conn.close()

# ============================================================
# SCHEDULER MANAGEMENT
# ============================================================

def load_schedules():
    """Load active schedules and add to APScheduler"""
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    cursor = conn.cursor()
    
    cursor.execute("SELECT * FROM schedules WHERE enabled = 1")
    schedules = cursor.fetchall()
    conn.close()
    
    # Clear existing jobs
    scheduler.remove_all_jobs()
    
    # Add jobs
    for schedule in schedules:
        add_schedule_job(dict(schedule))
    
    logging.info(f"Loaded {len(schedules)} active schedules")

def add_schedule_job(schedule):
    """Add schedule to APScheduler"""
    job_id = f"schedule_{schedule['id']}"
    
    # Parse time
    hour, minute = schedule['run_time'].split(':')
    
    # Create cron trigger based on frequency
    if schedule['frequency'] == 'hourly':
        trigger = CronTrigger(minute=minute)
    elif schedule['frequency'] == 'daily':
        trigger = CronTrigger(hour=hour, minute=minute)
    elif schedule['frequency'] == 'weekly':
        trigger = CronTrigger(day_of_week='mon', hour=hour, minute=minute)
    elif schedule['frequency'] == 'monthly':
        trigger = CronTrigger(day=1, hour=hour, minute=minute)
    else:
        logging.error(f"Unknown frequency: {schedule['frequency']}")
        return
    
    scheduler.add_job(
        execute_schedule,
        trigger=trigger,
        args=[schedule['id']],
        id=job_id,
        replace_existing=True
    )
    
    logging.info(f"Added job {job_id}: {schedule['frequency']} at {schedule['run_time']}")

# ============================================================
# API ENDPOINTS
# ============================================================

@app.route('/')
def index():
    """Health check"""
    return jsonify({
        'service': 'DQ Schedule Service',
        'version': '1.0',
        'status': 'running',
        'port': PORT
    })

@app.route('/schedules', methods=['GET', 'POST'])
def schedules():
    """List or create schedules"""
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    cursor = conn.cursor()
    
    if request.method == 'GET':
        cursor.execute("SELECT * FROM schedules ORDER BY created_at DESC")
        rows = cursor.fetchall()
        
        data = []
        for row in rows:
            schedule = dict(row)
            schedule['rule_ids'] = json.loads(schedule['rule_ids'])
            data.append(schedule)
        
        conn.close()
        return jsonify({'success': True, 'data': data})
    
    elif request.method == 'POST':
        data = request.json
        
        cursor.execute("""
            INSERT INTO schedules (name, rule_ids, frequency, run_time, notify_email, enabled)
            VALUES (?, ?, ?, ?, ?, ?)
        """, (
            data['name'],
            json.dumps(data['rule_ids']),
            data['frequency'],
            data['run_time'],
            data.get('notify_email'),
            data.get('enabled', True)
        ))
        
        schedule_id = cursor.lastrowid
        conn.commit()
        conn.close()
        
        # Reload schedules
        load_schedules()
        
        return jsonify({'success': True, 'id': schedule_id})

@app.route('/schedules/<int:schedule_id>', methods=['GET', 'PUT', 'DELETE'])
def schedule_detail(schedule_id):
    """Get, update or delete schedule"""
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    cursor = conn.cursor()
    
    if request.method == 'GET':
        cursor.execute("SELECT * FROM schedules WHERE id = ?", (schedule_id,))
        row = cursor.fetchone()
        
        if row:
            schedule = dict(row)
            schedule['rule_ids'] = json.loads(schedule['rule_ids'])
            conn.close()
            return jsonify({'success': True, 'data': schedule})
        else:
            conn.close()
            return jsonify({'success': False, 'error': 'Schedule not found'}), 404
    
    elif request.method == 'PUT':
        data = request.json
        
        cursor.execute("""
            UPDATE schedules 
            SET name = ?, rule_ids = ?, frequency = ?, run_time = ?,
                notify_email = ?, enabled = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        """, (
            data['name'],
            json.dumps(data['rule_ids']),
            data['frequency'],
            data['run_time'],
            data.get('notify_email'),
            data.get('enabled', True),
            schedule_id
        ))
        
        conn.commit()
        conn.close()
        
        # Reload schedules
        load_schedules()
        
        return jsonify({'success': True})
    
    elif request.method == 'DELETE':
        cursor.execute("DELETE FROM schedules WHERE id = ?", (schedule_id,))
        conn.commit()
        conn.close()
        
        # Reload schedules
        load_schedules()
        
        return jsonify({'success': True})

@app.route('/schedules/<int:schedule_id>/run', methods=['POST'])
def run_schedule(schedule_id):
    """Manually trigger schedule execution"""
    import threading
    
    # Get schedule name for response
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    cursor = conn.cursor()
    cursor.execute("SELECT name FROM schedules WHERE id = ?", (schedule_id,))
    schedule = cursor.fetchone()
    conn.close()
    
    if not schedule:
        return jsonify({'success': False, 'message': f'Schedule {schedule_id} not found'}), 404
    
    # Run in background thread so response is immediate
    thread = threading.Thread(target=execute_schedule, args=(schedule_id,))
    thread.start()
    
    return jsonify({
        'success': True, 
        'message': f'Schedule "{schedule["name"]}" execution started',
        'schedule_id': schedule_id
    })

@app.route('/executions', methods=['GET'])
def executions():
    """List executions"""
    schedule_id = request.args.get('schedule_id')
    limit = int(request.args.get('limit', 50))
    
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    cursor = conn.cursor()
    
    if schedule_id:
        cursor.execute("""
            SELECT e.*, COALESCE(e.schedule_name, s.name) as schedule_name 
            FROM executions e
            LEFT JOIN schedules s ON e.schedule_id = s.id
            WHERE e.schedule_id = ?
            ORDER BY e.started_at DESC 
            LIMIT ?
        """, (schedule_id, limit))
    else:
        cursor.execute("""
            SELECT e.*, COALESCE(e.schedule_name, s.name) as schedule_name 
            FROM executions e
            LEFT JOIN schedules s ON e.schedule_id = s.id
            ORDER BY e.started_at DESC 
            LIMIT ?
        """, (limit,))
    
    rows = cursor.fetchall()
    data = [dict(row) for row in rows]
    
    conn.close()
    return jsonify({'success': True, 'data': data})

@app.route('/executions/<int:execution_id>/results', methods=['GET'])
def execution_results(execution_id):
    """Get detailed results for execution"""
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    cursor = conn.cursor()
    
    cursor.execute("""
        SELECT * FROM rule_results 
        WHERE execution_id = ?
        ORDER BY id
    """, (execution_id,))
    
    rows = cursor.fetchall()
    data = [dict(row) for row in rows]
    
    conn.close()
    return jsonify({'success': True, 'data': data})

# ============================================================
# MAIN
# ============================================================

if __name__ == '__main__':
    print("=" * 60)
    print("DQ SCHEDULE SERVICE - SIMPLE VERSION")
    print("=" * 60)
    print(f"Port: {PORT}")
    print(f"DataRover URL: {DATAROVER_URL}")
    print(f"Database: {DB_FILE}")
    print("=" * 60)
    
    # Initialize database
    init_db()
    
    # Load schedules
    load_schedules()
    
    # Start scheduler
    scheduler.start()
    
    # Start Flask
    print("\n🚀 Service starting...")
    print(f"📍 Health check: http://localhost:{PORT}/")
    print("\n")
    
    try:
        app.run(host='0.0.0.0', port=PORT, debug=False)
    except (KeyboardInterrupt, SystemExit):
        scheduler.shutdown()
        print("\n✅ Service stopped")

