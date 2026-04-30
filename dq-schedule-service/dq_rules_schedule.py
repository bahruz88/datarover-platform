"""
DataRover - Data Quality Rules Schedule Service
Runs DQ Rules on schedule with full logging
"""

from flask import Flask, request, jsonify
from flask_cors import CORS
from apscheduler.schedulers.background import BackgroundScheduler
from apscheduler.triggers.cron import CronTrigger
import sqlite3
import json
import logging
from datetime import datetime
import threading
import requests

logging.basicConfig(level=logging.INFO, format='[%(asctime)s] %(levelname)s: %(message)s')
logger = logging.getLogger(__name__)

app = Flask(__name__)
CORS(app, resources={r"/*": {"origins": "*"}})

@app.after_request
def after_request(response):
    response.headers['Access-Control-Allow-Origin'] = '*'
    response.headers['Access-Control-Allow-Headers'] = 'Content-Type'
    response.headers['Access-Control-Allow-Methods'] = 'GET,POST,PUT,DELETE,OPTIONS'
    return response

DB_FILE = "dq_rules_schedules.db"
scheduler = BackgroundScheduler()

# ============================================================
# DATABASE INIT
# ============================================================

def init_db():
    """Initialize database"""
    conn = sqlite3.connect(DB_FILE)
    c = conn.cursor()
    
    # Schedules table
    c.execute('''
        CREATE TABLE IF NOT EXISTS rule_schedules (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            enabled INTEGER DEFAULT 1,
            frequency TEXT NOT NULL,
            run_time TEXT NOT NULL,
            connection_id INTEGER NOT NULL,
            rule_ids TEXT,
            notify_email TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ''')
    
    # Executions table with detailed logging
    c.execute('''
        CREATE TABLE IF NOT EXISTS rule_executions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            schedule_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP,
            connection_name TEXT,
            total_rules INTEGER DEFAULT 0,
            passed_rules INTEGER DEFAULT 0,
            failed_rules INTEGER DEFAULT 0,
            error_message TEXT,
            detailed_log TEXT,
            FOREIGN KEY (schedule_id) REFERENCES rule_schedules (id)
        )
    ''')
    
    # Rule results table (per rule details)
    c.execute('''
        CREATE TABLE IF NOT EXISTS rule_results (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            execution_id INTEGER NOT NULL,
            rule_id INTEGER NOT NULL,
            rule_name TEXT,
            status TEXT,
            passed_checks INTEGER DEFAULT 0,
            failed_checks INTEGER DEFAULT 0,
            total_checks INTEGER DEFAULT 0,
            error_message TEXT,
            execution_time REAL,
            result_details TEXT,
            FOREIGN KEY (execution_id) REFERENCES rule_executions (id)
        )
    ''')
    
    conn.commit()
    conn.close()
    logger.info("✅ Database initialized")

# ============================================================
# RUN SCHEDULED RULES
# ============================================================

def run_scheduled_rules(schedule_id: int):
    """Execute scheduled quality rules"""
    logger.info(f"▶️  Running scheduled rules for schedule {schedule_id}")
    
    conn = sqlite3.connect(DB_FILE)
    c = conn.cursor()
    
    try:
        # Get schedule details
        c.execute("SELECT * FROM rule_schedules WHERE id = ?", (schedule_id,))
        schedule = c.fetchone()
        
        if not schedule:
            logger.error(f"❌ Schedule {schedule_id} not found")
            return
        
        schedule_name = schedule[1]
        connection_id = schedule[5]
        rule_ids_json = schedule[6]
        
        # Create execution record
        c.execute(
            "INSERT INTO rule_executions (schedule_id, status) VALUES (?, 'running')",
            (schedule_id,)
        )
        conn.commit()
        execution_id = c.lastrowid
        
        detailed_log = []
        detailed_log.append(f"[{datetime.now()}] Starting schedule: {schedule_name}")
        
        # Get connection details from DataRover backend
        try:
            logger.info(f"🔗 Getting connection {connection_id} details")
            conn_response = requests.get(
                f"http://localhost/datarover/backend.php?action=get_connection&id={connection_id}",
                timeout=10
            )
            
            if conn_response.status_code != 200:
                raise Exception(f"Failed to get connection: {conn_response.status_code}")
            
            connection_data = conn_response.json()
            connection_name = connection_data.get('name', f'Connection {connection_id}')
            db_config = connection_data.get('config', {})
            
            detailed_log.append(f"[{datetime.now()}] Connected to: {connection_name}")
            detailed_log.append(f"[{datetime.now()}] Database: {db_config.get('database', 'N/A')}")
            
        except Exception as e:
            error_msg = f"Failed to get connection details: {str(e)}"
            logger.error(f"❌ {error_msg}")
            detailed_log.append(f"[{datetime.now()}] ERROR: {error_msg}")
            
            c.execute('''
                UPDATE rule_executions 
                SET status = 'error',
                    completed_at = CURRENT_TIMESTAMP,
                    error_message = ?,
                    detailed_log = ?
                WHERE id = ?
            ''', (error_msg, '\n'.join(detailed_log), execution_id))
            conn.commit()
            return
        
        # Parse rule IDs
        rule_ids = []
        if rule_ids_json:
            try:
                rule_ids = json.loads(rule_ids_json)
            except:
                rule_ids = [int(x.strip()) for x in rule_ids_json.split(',') if x.strip()]
        
        if not rule_ids:
            detailed_log.append(f"[{datetime.now()}] Running ALL rules for this connection")
        else:
            detailed_log.append(f"[{datetime.now()}] Running {len(rule_ids)} specific rules")
        
        # Run quality rules via DataRover backend
        total_rules = 0
        passed_rules = 0
        failed_rules = 0
        
        try:
            logger.info(f"🔍 Running quality rules")
            
            # Call DataRover backend to run rules
            run_response = requests.post(
                "http://localhost/datarover/backend.php?action=run_quality_rules",
                json={
                    "connection_id": connection_id,
                    "rule_ids": rule_ids if rule_ids else None
                },
                timeout=300
            )
            
            if run_response.status_code != 200:
                raise Exception(f"Failed to run rules: {run_response.status_code}")
            
            results = run_response.json()
            
            if results.get('success'):
                rule_results = results.get('results', [])
                total_rules = len(rule_results)
                
                detailed_log.append(f"[{datetime.now()}] Executed {total_rules} rules")
                
                # Process each rule result
                for rule_result in rule_results:
                    rule_id = rule_result.get('rule_id')
                    rule_name = rule_result.get('rule_name', f'Rule {rule_id}')
                    rule_status = rule_result.get('status', 'unknown')
                    passed_checks = rule_result.get('passed', 0)
                    failed_checks = rule_result.get('failed', 0)
                    total_checks = rule_result.get('total', 0)
                    exec_time = rule_result.get('execution_time', 0)
                    error = rule_result.get('error')
                    
                    if rule_status == 'passed':
                        passed_rules += 1
                        detailed_log.append(f"[{datetime.now()}] ✅ {rule_name}: PASSED ({passed_checks}/{total_checks})")
                    else:
                        failed_rules += 1
                        detailed_log.append(f"[{datetime.now()}] ❌ {rule_name}: FAILED ({failed_checks}/{total_checks})")
                        if error:
                            detailed_log.append(f"    Error: {error}")
                    
                    # Save rule result
                    c.execute('''
                        INSERT INTO rule_results 
                        (execution_id, rule_id, rule_name, status, passed_checks, failed_checks, 
                         total_checks, error_message, execution_time, result_details)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ''', (
                        execution_id,
                        rule_id,
                        rule_name,
                        rule_status,
                        passed_checks,
                        failed_checks,
                        total_checks,
                        error,
                        exec_time,
                        json.dumps(rule_result)
                    ))
                
                detailed_log.append(f"[{datetime.now()}] Summary: {passed_rules} passed, {failed_rules} failed")
                
                # Update execution record
                c.execute('''
                    UPDATE rule_executions 
                    SET status = 'completed',
                        completed_at = CURRENT_TIMESTAMP,
                        connection_name = ?,
                        total_rules = ?,
                        passed_rules = ?,
                        failed_rules = ?,
                        detailed_log = ?
                    WHERE id = ?
                ''', (
                    connection_name,
                    total_rules,
                    passed_rules,
                    failed_rules,
                    '\n'.join(detailed_log),
                    execution_id
                ))
                
                conn.commit()
                logger.info(f"✅ Schedule {schedule_id} completed: {passed_rules}/{total_rules} passed")
                
            else:
                raise Exception(results.get('error', 'Unknown error'))
                
        except Exception as e:
            error_msg = f"Failed to run rules: {str(e)}"
            logger.error(f"❌ {error_msg}")
            detailed_log.append(f"[{datetime.now()}] ERROR: {error_msg}")
            
            c.execute('''
                UPDATE rule_executions 
                SET status = 'error',
                    completed_at = CURRENT_TIMESTAMP,
                    connection_name = ?,
                    error_message = ?,
                    detailed_log = ?
                WHERE id = ?
            ''', (connection_name, error_msg, '\n'.join(detailed_log), execution_id))
            conn.commit()
        
    except Exception as e:
        logger.error(f"❌ Schedule {schedule_id} critical error: {str(e)}")
    
    finally:
        conn.close()

# ============================================================
# SCHEDULER FUNCTIONS
# ============================================================

def add_schedule_job(schedule_id: int, frequency: str, run_time: str):
    """Add schedule to APScheduler"""
    try:
        if frequency == 'hourly':
            trigger = CronTrigger(minute=0)
        elif frequency == 'daily':
            h, m = run_time.split(':')
            trigger = CronTrigger(hour=int(h), minute=int(m))
        elif frequency == 'weekly':
            h, m = run_time.split(':')
            trigger = CronTrigger(day_of_week='mon', hour=int(h), minute=int(m))
        elif frequency == 'monthly':
            h, m = run_time.split(':')
            trigger = CronTrigger(day=1, hour=int(h), minute=int(m))
        else:
            return
        
        scheduler.add_job(
            run_scheduled_rules,
            trigger=trigger,
            args=[schedule_id],
            id=f"schedule_{schedule_id}",
            replace_existing=True
        )
        logger.info(f"⏰ Added schedule job {schedule_id} ({frequency} at {run_time})")
        
    except Exception as e:
        logger.error(f"❌ Failed to add job {schedule_id}: {str(e)}")

def remove_schedule_job(schedule_id: int):
    """Remove schedule from APScheduler"""
    try:
        scheduler.remove_job(f"schedule_{schedule_id}")
        logger.info(f"🗑️  Removed schedule job {schedule_id}")
    except:
        pass

def load_active_schedules():
    """Load enabled schedules on startup"""
    conn = sqlite3.connect(DB_FILE)
    c = conn.cursor()
    c.execute("SELECT id, frequency, run_time FROM rule_schedules WHERE enabled = 1")
    schedules = c.fetchall()
    conn.close()
    
    for schedule_id, frequency, run_time in schedules:
        add_schedule_job(schedule_id, frequency, run_time)
    
    logger.info(f"📋 Loaded {len(schedules)} active schedule(s)")

# ============================================================
# API ENDPOINTS
# ============================================================

@app.route('/')
def health():
    return jsonify({
        "status": "healthy",
        "service": "DataRover DQ Rules Schedule Service",
        "scheduler": "running" if scheduler.running else "stopped"
    })

@app.route('/schedules', methods=['GET'])
def get_schedules():
    """Get all schedules"""
    conn = sqlite3.connect(DB_FILE)
    c = conn.cursor()
    c.execute("SELECT * FROM rule_schedules ORDER BY id DESC")
    
    schedules = []
    for row in c.fetchall():
        schedules.append({
            'id': row[0],
            'name': row[1],
            'enabled': bool(row[2]),
            'frequency': row[3],
            'run_time': row[4],
            'connection_id': row[5],
            'rule_ids': json.loads(row[6]) if row[6] else [],
            'notify_email': row[7],
            'created_at': row[8],
            'updated_at': row[9]
        })
    
    conn.close()
    return jsonify({'success': True, 'data': schedules})

@app.route('/schedules', methods=['POST'])
def create_schedule():
    """Create new schedule"""
    data = request.get_json()
    
    conn = sqlite3.connect(DB_FILE)
    c = conn.cursor()
    c.execute('''
        INSERT INTO rule_schedules 
        (name, enabled, frequency, run_time, connection_id, rule_ids, notify_email)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ''', (
        data.get('name'),
        1 if data.get('enabled', True) else 0,
        data.get('frequency'),
        data.get('run_time'),
        data.get('connection_id'),
        json.dumps(data.get('rule_ids', [])),
        data.get('notify_email')
    ))
    conn.commit()
    schedule_id = c.lastrowid
    conn.close()
    
    if data.get('enabled', True):
        add_schedule_job(schedule_id, data.get('frequency'), data.get('run_time'))
    
    logger.info(f"✅ Created schedule: {data.get('name')} (ID: {schedule_id})")
    
    return jsonify({'success': True, 'id': schedule_id})

@app.route('/schedules/<int:schedule_id>', methods=['PUT'])
def update_schedule(schedule_id):
    """Update schedule"""
    data = request.get_json()
    
    conn = sqlite3.connect(DB_FILE)
    c = conn.cursor()
    c.execute('''
        UPDATE rule_schedules 
        SET name = ?, enabled = ?, frequency = ?, run_time = ?,
            connection_id = ?, rule_ids = ?, notify_email = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ''', (
        data.get('name'),
        1 if data.get('enabled', True) else 0,
        data.get('frequency'),
        data.get('run_time'),
        data.get('connection_id'),
        json.dumps(data.get('rule_ids', [])),
        data.get('notify_email'),
        schedule_id
    ))
    conn.commit()
    conn.close()
    
    remove_schedule_job(schedule_id)
    if data.get('enabled', True):
        add_schedule_job(schedule_id, data.get('frequency'), data.get('run_time'))
    
    logger.info(f"✅ Updated schedule {schedule_id}")
    
    return jsonify({'success': True})

@app.route('/schedules/<int:schedule_id>', methods=['DELETE'])
def delete_schedule(schedule_id):
    """Delete schedule"""
    conn = sqlite3.connect(DB_FILE)
    c = conn.cursor()
    c.execute("DELETE FROM rule_schedules WHERE id = ?", (schedule_id,))
    conn.commit()
    conn.close()
    
    remove_schedule_job(schedule_id)
    
    logger.info(f"🗑️  Deleted schedule {schedule_id}")
    
    return jsonify({'success': True})

@app.route('/schedules/<int:schedule_id>/run', methods=['POST'])
def run_schedule_now(schedule_id):
    """Manually trigger schedule"""
    thread = threading.Thread(target=run_scheduled_rules, args=(schedule_id,))
    thread.start()
    
    logger.info(f"▶️  Manually triggered schedule {schedule_id}")
    
    return jsonify({'success': True, 'message': 'Schedule triggered'})

@app.route('/executions', methods=['GET'])
def get_executions():
    """Get execution history"""
    schedule_id = request.args.get('schedule_id', type=int)
    limit = request.args.get('limit', default=50, type=int)
    
    conn = sqlite3.connect(DB_FILE)
    c = conn.cursor()
    
    if schedule_id:
        c.execute(
            "SELECT * FROM rule_executions WHERE schedule_id = ? ORDER BY started_at DESC LIMIT ?",
            (schedule_id, limit)
        )
    else:
        c.execute("SELECT * FROM rule_executions ORDER BY started_at DESC LIMIT ?", (limit,))
    
    executions = []
    for row in c.fetchall():
        executions.append({
            'id': row[0],
            'schedule_id': row[1],
            'status': row[2],
            'started_at': row[3],
            'completed_at': row[4],
            'connection_name': row[5],
            'total_rules': row[6],
            'passed_rules': row[7],
            'failed_rules': row[8],
            'error_message': row[9],
            'detailed_log': row[10]
        })
    
    conn.close()
    
    return jsonify({'success': True, 'data': executions})

@app.route('/executions/<int:execution_id>/results', methods=['GET'])
def get_execution_results(execution_id):
    """Get detailed rule results for an execution"""
    conn = sqlite3.connect(DB_FILE)
    c = conn.cursor()
    c.execute("SELECT * FROM rule_results WHERE execution_id = ? ORDER BY id", (execution_id,))
    
    results = []
    for row in c.fetchall():
        results.append({
            'id': row[0],
            'execution_id': row[1],
            'rule_id': row[2],
            'rule_name': row[3],
            'status': row[4],
            'passed_checks': row[5],
            'failed_checks': row[6],
            'total_checks': row[7],
            'error_message': row[8],
            'execution_time': row[9],
            'result_details': json.loads(row[10]) if row[10] else None
        })
    
    conn.close()
    
    return jsonify({'success': True, 'data': results})

# ============================================================
# START
# ============================================================

if __name__ == "__main__":
    logger.info("=" * 60)
    logger.info("🚀 DataRover DQ Rules Schedule Service")
    logger.info("=" * 60)
    
    init_db()
    scheduler.start()
    load_active_schedules()
    
    logger.info("✅ Service ready!")
    logger.info("=" * 60)
    
    app.run(host="0.0.0.0", port=8001, debug=False)
