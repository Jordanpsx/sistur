### 1. Mandatory Audit Logging (Employee Portal)
For every new feature, function, or mutation implemented within the **Employee Portal** (Portal do Colaborador), the implementation of a comprehensive audit log is **strictly required**.

The system must track any state change or significant action. Each log entry must explicitly capture the following metadata:

* **Action Context:** A clear description of what operation was performed.
* **Previous State (Snapshot):** The data values *before* the modification (diff start).
* **New State (Snapshot):** The data values *after* the modification (diff end).
* **Actor:** The ID and Name of the employee who performed the action.
* **Timestamp:** The exact date and time of the event (ISO 8601).

### 2. Database
The sistur database is located on the remote VPS, we can't access it directly, but we can access it through the terminal. The VPS address is 76.13.161.220

### 3. 
If necessary, you can made a deploy on VPS using the git commit and git push command to develop branch. When you push the code to develop branch, the git hook will be triggered and the code will be deployed to the VPS. 

### 4.

Don't make changes in wordpress admin panel if now asked for you do in this way. Make the changes on portal do colaborador

### 5.
- Before make changes read CLAUDE.MD if you don't readed