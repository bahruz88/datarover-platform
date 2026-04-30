"""
Soda Core Microservice for DataRover
REST API for running quality scans
"""

from fastapi import FastAPI, HTTPException, BackgroundTasks
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from typing import Optional, List, Dict
import asyncio
from datetime import datetime
import json
from pathlib import Path
import sys

# Add parent directory to path
sys.path.insert(0, str(Path(__file__).parent))
from soda_scanner import SodaScanner

app = FastAPI(
    title="DataRover Soda Service",
    description="Data Quality Scanning Service using Soda Core",
    version="1.0.0"
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

scanner = SodaScanner()

# Store for background scan status
scan_status: Dict[str, Dict] = {}


class ScanRequest(BaseModel):
    data_source: str
    check_files: Optional[List[str]] = None
    tables: Optional[List[str]] = None
    scan_name: Optional[str] = None
    variables: Optional[Dict[str, str]] = None


class ScanResponse(BaseModel):
    scan_id: str
    status: str
    message: str


@app.get("/")
async def root():
    return {
        "service": "DataRover Soda Service",
        "version": "1.0.0",
        "status": "running",
        "endpoints": {
            "health": "/health",
            "scan": "POST /scan",
            "scan_status": "/scan/{scan_id}",
            "results": "/results/latest",
            "checks": "/checks"
        }
    }


@app.get("/health")
async def health():
    return {
        "status": "healthy",
        "timestamp": datetime.now().isoformat(),
        "soda_available": True
    }


@app.post("/scan", response_model=ScanResponse)
async def run_scan(request: ScanRequest, background_tasks: BackgroundTasks):
    """Start a data quality scan"""
    scan_id = f"scan_{datetime.now().strftime('%Y%m%d_%H%M%S')}"
    
    # Initialize scan status
    scan_status[scan_id] = {
        "status": "running",
        "started_at": datetime.now().isoformat(),
        "data_source": request.data_source,
        "results": None
    }
    
    # Run scan in background
    background_tasks.add_task(
        execute_scan,
        scan_id,
        request.data_source,
        request.check_files or ["checks/"],
        request.scan_name,
        request.variables
    )
    
    return ScanResponse(
        scan_id=scan_id,
        status="started",
        message="Scan started in background"
    )


async def execute_scan(
    scan_id: str,
    data_source: str,
    check_files: List[str],
    scan_name: Optional[str],
    variables: Optional[Dict]
):
    """Execute scan in background"""
    try:
        results = scanner.run_scan(
            data_source=data_source,
            checks_paths=check_files,
            scan_name=scan_name or scan_id,
            variables=variables
        )
        
        scanner.save_results(results, f"{scan_id}.json")
        
        scan_status[scan_id] = {
            "status": "completed",
            "started_at": scan_status[scan_id]["started_at"],
            "completed_at": datetime.now().isoformat(),
            "data_source": data_source,
            "results": results
        }
        
    except Exception as e:
        scan_status[scan_id] = {
            "status": "failed",
            "started_at": scan_status[scan_id]["started_at"],
            "data_source": data_source,
            "error": str(e)
        }


@app.post("/scan/sync")
async def run_scan_sync(request: ScanRequest):
    """Run scan synchronously and return results"""
    results = scanner.run_scan(
        data_source=request.data_source,
        checks_paths=request.check_files or ["checks/"],
        scan_name=request.scan_name,
        variables=request.variables
    )
    
    scanner.save_results(results)
    return results


@app.get("/scan/{scan_id}")
async def get_scan_status(scan_id: str):
    """Get status of a scan"""
    if scan_id not in scan_status:
        raise HTTPException(status_code=404, detail="Scan not found")
    
    status = scan_status[scan_id]
    
    # Return summary without full results
    response = {
        "scan_id": scan_id,
        "status": status["status"],
        "started_at": status["started_at"],
        "data_source": status.get("data_source")
    }
    
    if status["status"] == "completed":
        response["completed_at"] = status.get("completed_at")
        response["summary"] = status["results"].get("summary") if status.get("results") else None
    elif status["status"] == "failed":
        response["error"] = status.get("error")
    
    return response


@app.get("/scan/{scan_id}/results")
async def get_scan_results(scan_id: str):
    """Get full results of a completed scan"""
    if scan_id not in scan_status:
        raise HTTPException(status_code=404, detail="Scan not found")
    
    status = scan_status[scan_id]
    
    if status["status"] != "completed":
        raise HTTPException(
            status_code=400,
            detail=f"Scan is {status['status']}, results not available"
        )
    
    return status["results"]


@app.get("/results/latest")
async def get_latest_results():
    """Get the latest scan results"""
    results = scanner.get_latest_results()
    
    if not results:
        raise HTTPException(status_code=404, detail="No results available")
    
    return results


@app.get("/results")
async def list_results(limit: int = 10):
    """List available scan results"""
    results_dir = Path("results/history")
    
    if not results_dir.exists():
        return {"results": []}
    
    files = sorted(results_dir.glob("*.json"), reverse=True)[:limit]
    
    results = []
    for f in files:
        try:
            with open(f) as file:
                data = json.load(file)
                results.append({
                    "filename": f.name,
                    "scan_name": data.get("scan_name"),
                    "executed_at": data.get("executed_at"),
                    "score": data.get("summary", {}).get("score"),
                    "total_checks": data.get("summary", {}).get("total_checks")
                })
        except:
            continue
    
    return {"results": results}


@app.get("/checks")
async def list_available_checks():
    """List all available check files"""
    checks_dir = Path("checks")
    
    if not checks_dir.exists():
        return {"checks": []}
    
    checks = []
    for f in checks_dir.rglob("*.yml"):
        checks.append({
            "path": str(f.relative_to(checks_dir)),
            "name": f.stem,
            "category": f.parent.name if f.parent != checks_dir else "root"
        })
    
    return {"checks": checks}


@app.get("/datasources")
async def list_datasources():
    """List configured data sources"""
    config_dir = Path("config")
    
    if not config_dir.exists():
        return {"datasources": []}
    
    datasources = []
    for f in config_dir.glob("*_connection.yml"):
        name = f.stem.replace("_connection", "")
        datasources.append({
            "name": f"{name}_db",
            "config_file": f.name,
            "type": name
        })
    
    return {"datasources": datasources}


@app.delete("/scan/{scan_id}")
async def delete_scan(scan_id: str):
    """Delete scan from status cache"""
    if scan_id in scan_status:
        del scan_status[scan_id]
        return {"message": f"Scan {scan_id} deleted"}
    raise HTTPException(status_code=404, detail="Scan not found")


if __name__ == "__main__":
    import uvicorn
    print("\n" + "="*60)
    print("DataRover Soda Service")
    print("="*60)
    print("Starting server on http://0.0.0.0:8001")
    print("="*60 + "\n")
    uvicorn.run(app, host="0.0.0.0", port=8001)
