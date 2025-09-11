## 🛠️ Development Status

The PHP Image Board is an active project with several completed systems. This section highlights what’s already working.

---

## 📋 System Requirements

For detailed hardware, software, and storage recommendations to run the PHP Image Board efficiently, see the [requirements.md](docs/requirements.md) file.

---

### ✅ Completed Systems
- **User Registration & Login**
- **Change Account E-mail**
- **Upload User Avatar**
- **Sensitive Content System** → Users must input their birthday to view restricted content.
- **Change Account Password**
- **View Uploaded Images**
- **Image Board Pagination**
- **Advanced Duplicate Image Detection** → Uses aHash, pHash, and dHash to detect duplicates.
- **Up-Voting & Favorites** → Users can up-vote and favorite images.
- **Moderation Panel**

---

## 🔄 Duplicate Detection Workflow

1. **Image Upload**  
2. **Generate Hashes** (aHash, pHash, dHash)  
3. **Compare Against Database**  
4. **Flag Potential Duplicates**  
5. **Human Moderator Review**  
6. **Decision: Approve or Reject**

---

## 💡 Why This Matters

Duplicate detection improves:

- **Storage Efficiency** → Avoids repeated images.  
- **User Experience** → Reduces clutter.  
- **Community Value** → Encourages original contributions.  
- **Moderator Support** → Streamlines review workflow.

Combines **automation** with **human review** for speed, accuracy, and fairness.

---

## License

This project is licensed under the **GNU Affero General Public License v3.0 (AGPLv3)**.  
See the [LICENSE](LICENSE) file for details.

**Summary**

- ✅ You may use, modify, and distribute this software under AGPLv3.  
- ✅ Contributions via pull requests are welcome.  
- ❌ You may **not** relicense under closed-source or proprietary licenses.  
- ❌ You may **not** copy parts of this project into another project unless that project is also AGPLv3-compatible.  
- ✅ Server deployments must provide source code to users.
