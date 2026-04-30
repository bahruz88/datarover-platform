#!/usr/bin/env python3
"""
DataRover API Integration for Soda Core
Sends scan results to DataRover backend
"""

import requests
import json
import os
from datetime import datetime
from typing import Optional, Dict, Any
from soda_scanner import SodaScanner


class DataRoverIntegration:
    """Integration class for sending Soda results to DataRover API"""
    
    def __init__(self, api_url: str = None, api_key: str = None):
        """
        Initialize with DataRover API URL
        
        Args:
            api_url: DataRover backend URL (e.g., http://localhost/datarover/backend.php)
            api_key: Optional API key for authentication
        """
        self.api_url = api_url or os.getenv('DATAROVER_API_URL', 'http://localhost/datarover/backend.php')
        self.api_key = api_key or os.getenv('DATAROVER_API_KEY')
        self.scanner = SodaScanner()
    
    def _get_headers(self) -> Dict[str, str]:
        """Get request headers with optional auth"""
        headers = {'Content-Type': 'application/json'}
        if self.api_key:
            headers['Authorization'] = f'Bearer {self.api_key}'
        return headers
    
    def send_results(self, results: Dict[str, Any], source_id: int = None) -> Dict:
        """
        Send scan results to DataRover API
        
        Args:
            results: Soda scan results dictionary
            source_id: Optional DataRover data source ID
            
        Returns:
            API response
        """
        endpoint = f"{self.api_url}?action=soda_scans"
        
        # Add source_id if provided
        if source_id:
            results['source_id'] = source_id
        
        try:
            response = requests.post(
                endpoint,
                json=results,
                headers=self._get_headers(),
                timeout=30
            )
            response.raise_for_status()
            return response.json()
        except requests.exceptions.RequestException as e:
            return {'error': str(e), 'status': 'failed'}
    
    def run_and_send(
        self,
        data_source: str,
        checks_paths: list = None,
        scan_name: str = None,
        source_id: int = None,
        variables: Dict[str, str] = None
    ) -> Dict:
        """
        Run Soda scan and send results to DataRover
        
        Args:
            data_source: Name of the data source in Soda config
            checks_paths: List of paths to check files
            scan_name: Name for this scan
            source_id: DataRover data source ID
            variables: Variables to pass to Soda scan
            
        Returns:
            Combined scan and API response
        """
        scan_name = scan_name or f"scan_{data_source}_{datetime.now().strftime('%Y%m%d_%H%M%S')}"
        checks_paths = checks_paths or ['checks/']
        
        print(f"🔍 Running Soda scan: {scan_name}")
        print(f"   Data source: {data_source}")
        print(f"   Checks: {checks_paths}")
        
        # Run the scan
        results = self.scanner.run_scan(
            data_source=data_source,
            checks_paths=checks_paths,
            scan_name=scan_name,
            variables=variables
        )
        
        # Print summary
        summary = results.get('summary', {})
        print(f"\n📊 Scan Results:")
        print(f"   Total checks: {summary.get('total_checks', 0)}")
        print(f"   Passed: {summary.get('passed', 0)}")
        print(f"   Warnings: {summary.get('warnings', 0)}")
        print(f"   Failures: {summary.get('failures', 0)}")
        print(f"   Score: {summary.get('score', 0)}%")
        
        # Send to DataRover
        print(f"\n📤 Sending results to DataRover...")
        api_response = self.send_results(results, source_id)
        
        if 'error' in api_response:
            print(f"   ❌ Error: {api_response['error']}")
        else:
            print(f"   ✅ Saved with ID: {api_response.get('id')}")
        
        return {
            'scan_results': results,
            'api_response': api_response
        }
    
    def get_latest_results(self, source_id: int = None, limit: int = 10) -> Dict:
        """
        Get latest scan results from DataRover
        
        Args:
            source_id: Filter by data source ID
            limit: Number of results to fetch
            
        Returns:
            List of scan results
        """
        endpoint = f"{self.api_url}?action=soda_scans&limit={limit}"
        if source_id:
            endpoint += f"&source_id={source_id}"
        
        try:
            response = requests.get(endpoint, headers=self._get_headers(), timeout=30)
            response.raise_for_status()
            return response.json()
        except requests.exceptions.RequestException as e:
            return {'error': str(e)}
    
    def get_quality_summary(self) -> Dict:
        """
        Get quality summary across all data sources
        
        Returns:
            Summary statistics per data source
        """
        endpoint = f"{self.api_url}?action=soda_summary"
        
        try:
            response = requests.get(endpoint, headers=self._get_headers(), timeout=30)
            response.raise_for_status()
            return response.json()
        except requests.exceptions.RequestException as e:
            return {'error': str(e)}


def main():
    """CLI interface"""
    import argparse
    
    parser = argparse.ArgumentParser(description='DataRover Soda Integration')
    parser.add_argument('-d', '--data-source', required=True, help='Soda data source name')
    parser.add_argument('-c', '--checks', nargs='+', default=['checks/'], help='Check file paths')
    parser.add_argument('-n', '--scan-name', help='Name for this scan')
    parser.add_argument('-s', '--source-id', type=int, help='DataRover source ID')
    parser.add_argument('--api-url', help='DataRover API URL')
    parser.add_argument('-v', '--variable', action='append', help='Variables (KEY=VALUE)')
    parser.add_argument('--list', action='store_true', help='List recent scan results')
    parser.add_argument('--summary', action='store_true', help='Show quality summary')
    
    args = parser.parse_args()
    
    integration = DataRoverIntegration(api_url=args.api_url)
    
    if args.list:
        results = integration.get_latest_results(source_id=args.source_id)
        print(json.dumps(results, indent=2))
        return
    
    if args.summary:
        summary = integration.get_quality_summary()
        print(json.dumps(summary, indent=2))
        return
    
    # Parse variables
    variables = {}
    if args.variable:
        for v in args.variable:
            key, value = v.split('=', 1)
            variables[key] = value
    
    # Run scan and send
    result = integration.run_and_send(
        data_source=args.data_source,
        checks_paths=args.checks,
        scan_name=args.scan_name,
        source_id=args.source_id,
        variables=variables if variables else None
    )
    
    # Save result to file
    output_file = f"results/datarover_result_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
    os.makedirs('results', exist_ok=True)
    with open(output_file, 'w') as f:
        json.dump(result, f, indent=2)
    print(f"\n💾 Full results saved to: {output_file}")


if __name__ == '__main__':
    main()
