"""
Base Database Connector - Abstract class for all database connectors
"""

from abc import ABC, abstractmethod
from typing import Optional, List, Dict, Any


class BaseConnector(ABC):
    """Abstract base class for database connectors"""
    
    def __init__(self, host: str, port: int, username: str, password: str, 
                 database: Optional[str] = None, sid: Optional[str] = None):
        self.host = host
        self.port = port
        self.username = username
        self.password = password
        self.database = database
        self.sid = sid
        self._connection = None
    
    @abstractmethod
    def connect(self):
        """Establish database connection"""
        pass
    
    @abstractmethod
    def disconnect(self):
        """Close database connection"""
        pass
    
    @abstractmethod
    def test_connection(self) -> Dict[str, Any]:
        """Test connection and return status"""
        pass
    
    @abstractmethod
    def list_schemas(self) -> List[Dict[str, Any]]:
        """List all schemas/databases"""
        pass
    
    @abstractmethod
    def list_tables(self, schema: Optional[str] = None) -> List[Dict[str, Any]]:
        """List all tables in a schema"""
        pass
    
    @abstractmethod
    def get_columns(self, schema: str, table: str) -> List[Dict[str, Any]]:
        """Get column details for a table"""
        pass
    
    @abstractmethod
    def get_primary_keys(self, schema: str, table: str) -> List[str]:
        """Get primary key columns"""
        pass
    
    @abstractmethod
    def get_foreign_keys(self, schema: str, table: str) -> List[Dict[str, Any]]:
        """Get foreign key relationships"""
        pass
    
    def get_indexes(self, schema: str, table: str) -> List[Dict[str, Any]]:
        """Get indexes for a table (optional implementation)"""
        return []
    
    def get_sample_data(self, schema: str, table: str, limit: int = 10) -> List[Dict[str, Any]]:
        """Get sample data from table (optional implementation)"""
        return []
